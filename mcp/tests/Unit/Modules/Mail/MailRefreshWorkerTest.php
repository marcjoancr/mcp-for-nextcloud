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
final class MailRefreshWorkerTest extends TestCase {
    public static function request():array {return ['kind'=>'refresh','account_id'=>4,'mailbox_id'=>5,'mailbox'=>'INBOX','profile_revision'=>str_repeat('a',64),'cache_revision'=>str_repeat('b',64),'allow_cache_reset'=>false];}
    /** @dataProvider failures */
    public function testReadRefusalIsBeforeEffectAndCacheLossNeverReplays(string $mode,string $state,string $phase):void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());$ctx=new ConnectionContext();$ctx->set(4);$clock=$this->createStub(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);$proof=new PlanState((new InMemoryConfig())->mock($this));$journal=new OperationJournal($store,$proof,$ctx,$clock, new \OCA\Mcp\Tests\Support\OperationActors());$tickets=new MutationTicket($proof,$clock);$r=self::request();$target=$this->createMock(MailMutationTarget::class);$target->method('snapshot')->willReturn(['request'=>$r]);
        $service=new class($mode) {
            public int $reads=0;public int $writes=0;
            public function __construct(private string $mode){}
            public function fence(object $f):object{return $f;}
            public function refresh(string $uid,array $r,object $f):array {
                if(!$f->authorizeRead($r))throw new \RuntimeException();$this->reads++;if($this->mode==='read')throw new \RuntimeException('Remote limit');
                $p=['outcome'=>'observed','mailbox_id'=>5,'generation'=>'42','messages'=>2,'reset'=>false];if(!$f->beforeCache($r,$p))throw new \RuntimeException();$this->writes++;if($this->mode==='cache')throw new \RuntimeException('Acknowledgment lost');
                if(!$f->recordCache(['state'=>'refreshed','mailbox_id'=>5,'generation'=>'42','messages'=>2,'reset'=>false,'observed_at'=>1000,'threads_state'=>'native_rebuild_pending']))throw new \RuntimeException();return [];
            }
        };
        $native=$this->createMock(MailServices::class);$native->method('guardedRefresh')->willReturn($service);$native->expects(self::never())->method('guardedMutations');$worker=new NativeMutationWorker($tickets,$target,$native,$store,$journal,$clock,static fn():bool=>true);$a=['account_id'=>4,'mailbox_id'=>5];$p=$journal->plan('mail_refresh_mailbox','alice',$a,['plan_state'=>'proof']);$a+=['plan_state'=>'proof','operation_id'=>$p['operation_id']];$ticket=null;$effect=function()use($journal,$worker,$tickets,$r,&$ticket):array {$lease=$journal->delegateNative('alice',$r);$ticket=$tickets->create('alice',4,$lease,$r);return $worker->run($ticket);};$result=$journal->execute('mail_refresh_mailbox','alice',$a,$effect);self::assertSame($state,$result['operation']['state']);self::assertSame($phase,$result['operation']['mutation']['phase']);self::assertSame(['status'=>'refused'],$worker->run($ticket));$journal->execute('mail_refresh_mailbox','alice',$a,$effect);self::assertSame(1,$service->reads);self::assertSame($mode==='read'?0:1,$service->writes);
    }
    public static function failures():array{return [['read','failed_before_effect','prepared'],['cache','uncertain','cache_pending'],['ok','succeeded','completed']];}
}
