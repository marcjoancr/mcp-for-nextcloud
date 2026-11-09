<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Db\OperationsMapper;
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Mail\Integration\{MailMutationTarget,MailServices};
use OCA\Mcp\Modules\Mail\Mutations\{NativeMutationWorker,MutationTicket};
use OCA\Mcp\Migration\Version002700Date20261107000000;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Unit\{SqliteDatabase,InMemoryConfig};
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class MailTagMutationWorkerTest extends TestCase {
    public function testRevocationAfterRemoteEffectRetainsProofAndPreventsCacheAndReplay():void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        $ctx=new ConnectionContext();$ctx->set(4);$clock=$this->createStub(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $proof=new PlanState((new InMemoryConfig())->mock($this));$journal=new OperationJournal($store,$proof,$ctx,$clock);$tickets=new MutationTicket($proof,$clock);
        $r=MailTagMutationRequestTest::request();
        $target=$this->createMock(MailMutationTarget::class);$target->method('snapshot')->willReturn(['request'=>$r,'before'=>false]);
        $allowed=true;$authorize=static function()use(&$allowed):bool{return $allowed;};
        $service=new class($allowed) {
            public int $writes=0;public bool $cache=false;
            public function __construct(public bool &$allowed){}
            public function fence(object $f):object{return $f;}
            public function setTag(string $uid,array $r,object $f):array {
                if(!$f->beforeWire($r))throw new \RuntimeException('refused');$this->writes++;
                $f->recordRemote(['outcome'=>'verified','message_id'=>17,'mailbox_id'=>5,'generation'=>'42','uid'=>'9','tag_id'=>7,'value'=>true]);
                $this->allowed=false;if(!$f->beforeCache($r))throw new \RuntimeException('revoked');$this->cache=true;return [];
            }
        };
        $native=$this->createMock(MailServices::class);$native->method('guardedMutations')->willReturn($service);
        $worker=new NativeMutationWorker($tickets,$target,$native,$store,$journal,$clock,$authorize);
        $a=['account_id'=>4,'message_id'=>17,'tag_id'=>7,'value'=>true];$p=$journal->plan('mail_set_message_tag','alice',$a,['plan_state'=>'proof']);$a+=['plan_state'=>'proof','operation_id'=>$p['operation_id']];$ticket=null;
        $effect=function()use($journal,$worker,$tickets,$r,&$ticket):array{$lease=$journal->delegateNative('alice',$r);$ticket=$tickets->create('alice',4,$lease,$r);return $worker->run($ticket);};
        $result=$journal->execute('mail_set_message_tag','alice',$a,$effect);self::assertSame('uncertain',$result['operation']['state']);self::assertSame('remote_verified',$result['operation']['mutation']['phase']);
        $allowed=true;self::assertSame(['status'=>'refused'],$worker->run($ticket));$journal->execute('mail_set_message_tag','alice',$a,$effect);
        self::assertSame(1,$service->writes);self::assertFalse($service->cache);self::assertNotNull($store->get($journal->scopeKey('alice'),$a['operation_id'])['target_key']);
    }
}
