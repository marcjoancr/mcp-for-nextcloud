<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Db;
use OCA\Mcp\Db\OperationsMapper;
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Migration\Version002700Date20261107000000;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Unit\{SqliteDatabase,InMemoryConfig};
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class MailMutationJournalTest extends TestCase {
    private function fixture():array {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        $context=new ConnectionContext();$context->set(4);$clock=$this->createStub(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        return [$db,$store,new OperationJournal($store,new PlanState((new InMemoryConfig())->mock($this)),$context,$clock, new \OCA\Mcp\Tests\Support\OperationActors())];
    }
    private function descriptor():array {return ['account_id'=>4,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','flag'=>'seen','value'=>true,'profile_revision'=>'profile','cache_revision'=>'cache'];}
    private function args(OperationJournal $j):array {$a=['account_id'=>4,'message_id'=>17,'flag'=>'seen','value'=>true];$p=$j->plan('mail_set_message_flag','alice',$a,['plan_state'=>'approved']);return $a+['plan_state'=>'approved','operation_id'=>$p['operation_id']];}
    public function testLostWorkerResultUsesAuthoritativeProofAndNeverReexecutes():void {
        [$db,$s,$j]=$this->fixture();$args=$this->args($j);$calls=0;
        $effect=function()use($j,$s,&$calls):array {
            $calls++;self::assertTrue(method_exists($j,'delegateNative'),'Durable native delegation is required.');
            $lease=$j->delegateNative('alice',$this->descriptor());
            self::assertTrue($s->checkpointNative($lease['scope'],$lease['id'],$lease['token'],'prepared',['phase'=>'wire_may_start']));
            self::assertTrue($s->checkpointNative($lease['scope'],$lease['id'],$lease['token'],'wire_may_start',['phase'=>'remote_verified','remote'=>['outcome'=>'verified']]));
            throw new \RuntimeException('Lost child output');
        };
        $r=$j->execute('mail_set_message_flag','alice',$args,$effect);
        self::assertSame('uncertain',$r['operation']['state']);self::assertSame('remote_verified',$r['operation']['mutation']['phase']);
        $r=$j->execute('mail_set_message_flag','alice',$args,$effect);self::assertSame(1,$calls);self::assertTrue($r['operation']['replayed']);
        self::assertStringNotContainsString('token',json_encode($r));self::assertStringNotContainsString('profile',json_encode($r));
        self::assertNotNull($s->get($j->scopeKey('alice'),$args['operation_id'])['target_key']);
    }
    public function testWrongTokenAndStaleParentCannotReplaceNewerEvidence():void {
        [$db,$s,$j]=$this->fixture();$args=$this->args($j);
        $r=$j->execute('mail_set_message_flag','alice',$args,function()use($j,$s):array{
            self::assertTrue(method_exists($j,'delegateNative'),'Durable native delegation is required.');$l=$j->delegateNative('alice',$this->descriptor());
            self::assertFalse($s->checkpointNative($l['scope'],$l['id'],'wrong','prepared',['phase'=>'wire_may_start']));
            self::assertTrue($s->checkpointNative($l['scope'],$l['id'],$l['token'],'prepared',['phase'=>'wire_may_start']));
            self::assertFalse($s->finishNative($l['scope'],$l['id'],$l['token'],'prepared','failed_before_effect',[],1001));
            self::assertTrue($s->checkpointNative($l['scope'],$l['id'],$l['token'],'wire_may_start',['phase'=>'remote_verified','remote'=>['outcome'=>'verified']]));
            self::assertTrue($s->checkpointNative($l['scope'],$l['id'],$l['token'],'remote_verified',['phase'=>'cache_pending']));
            self::assertTrue($s->checkpointNative($l['scope'],$l['id'],$l['token'],'cache_pending',['phase'=>'completed','cache'=>['state'=>'updated']]));
            return ['untrusted'=>'output ignored'];
        });
        self::assertSame('succeeded',$r['operation']['state']);self::assertSame('completed',$r['operation']['mutation']['phase']);
        self::assertArrayNotHasKey('untrusted',$r);self::assertNull($s->get($j->scopeKey('alice'),$args['operation_id'])['target_key']);
    }
    public function testExpiredDelegatedWorkerIsReportedUncertainWithoutReleasingItsClaim():void {
        [$db,$store,$journal]=$this->fixture();$scope=$journal->scopeKey('alice');
        $store->reserve(['scope_key'=>$scope,'operation_id'=>'expired-worker','tool_name'=>'mail_set_message_flag','effect_digest'=>'effect','target_key'=>'retained-claim','actor_uid'=>'alice','state'=>'running',
            'resource_refs'=>['actor_incarnation'=>$journal->actor('alice'),'account_id'=>4,'mutation'=>['phase'=>'wire_may_start','expires_at'=>995]],'created_at'=>970,'updated_at'=>970]);
        self::assertSame('uncertain',$journal->get('alice','expired-worker')['state']);self::assertSame('retained-claim',$store->get($scope,'expired-worker')['target_key']);
    }
    public function testDirectCompletionCannotOverwriteDelegatedState():void {
        [$db,$s,$j]=$this->fixture();$a=$this->args($j);$blocked=false;
        $r=$j->execute('mail_set_message_flag','alice',$a,function()use($j,$s,&$blocked):array{
            $l=$j->delegateNative('alice',$this->descriptor());
            try{$s->finish($l['scope'],$l['id'],'succeeded',['account_id'=>4],1000);}catch(\RuntimeException){$blocked=true;}
            return [];
        });
        self::assertTrue($blocked,'The old direct finish must not accept delegated rows.');self::assertSame('failed_before_effect',$r['operation']['state']);
    }
    public function testTimeoutBeforeWireRevokesPermitAndLateWorkerCannotBegin():void {
        [$db,$s,$j]=$this->fixture();$args=$this->args($j);$l=null;
        $r=$j->execute('mail_set_message_flag','alice',$args,function()use($j,&$l):array{
            self::assertTrue(method_exists($j,'delegateNative'),'Durable native delegation is required.');$l=$j->delegateNative('alice',$this->descriptor());throw new \RuntimeException('timeout');
        });
        self::assertSame('failed_before_effect',$r['operation']['state']);
        self::assertFalse($s->checkpointNative($l['scope'],$l['id'],$l['token'],'prepared',['phase'=>'wire_may_start']));
    }
}
