<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Db;
use OCA\Mcp\Db\OperationsMapper;
use OCA\Mcp\Migration\Version002700Date20261107000000;
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Unit\{SqliteDatabase,InMemoryConfig};
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class OperationsMapperTest extends TestCase {
    public function testReservationAndTargetExclusionAreEnforcedByDatabaseConstraints(): void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);
        $store=new OperationsMapper($db->connection());
        $row=['scope_key'=>str_repeat('a',64),'operation_id'=>'one','tool_name'=>'mail_write_draft','effect_digest'=>str_repeat('c',64),
            'target_key'=>str_repeat('d',64),'state'=>'running','resource_refs'=>[],'created_at'=>100,'updated_at'=>100];
        self::assertTrue($store->reserve($row));
        self::assertFalse($store->reserve($row));
        self::assertFalse($store->reserve(array_replace($row,['scope_key'=>str_repeat('b',64),'operation_id'=>'two'])));
        $store->checkpoint($row['scope_key'],'one',['draft_id'=>77]);
        $store->finish($row['scope_key'],'one','succeeded',['draft_id'=>77],101);
        self::assertSame(['draft_id'=>77],$store->get($row['scope_key'],'one')['resource_refs']);
        self::assertTrue($store->reserve(array_replace($row,['operation_id'=>'two','created_at'=>102])));
        self::assertSame(['two','one'],array_column($store->list($row['scope_key'],2,0),'operation_id'));
        self::assertSame(['one'],array_column($store->list($row['scope_key'],1,1),'operation_id'));
        self::assertFalse($store->reserve($row),'Completed operation keys remain non-replay tombstones.');
        self::assertNull($store->get(str_repeat('f',64),'one'));
    }
    public function testOnlyOneRepairOrCleanupCanClaimAPartialOperation(): void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);
        $store=new OperationsMapper($db->connection());
        $row=['scope_key'=>'scope','operation_id'=>'partial','actor_uid'=>'alice','tool_name'=>'mail_write_draft','effect_digest'=>'digest',
            'target_key'=>null,'state'=>'running','resource_refs'=>[],'created_at'=>100,'updated_at'=>100];
        $store->reserve($row);
        $store->finish('scope','partial','partial',['staged'=>[['attachment_id'=>9]]],100);
        self::assertTrue($store->claimPartial('scope','partial','repairing'));
        self::assertFalse($store->claimPartial('scope','partial','cleaning'));
        self::assertSame([], $store->cleanupCandidates(1000,100));
        $store->settleRepair('scope','partial',[['attachment_id'=>9]]);
        self::assertSame([], $store->get('scope','partial')['resource_refs']['staged'],'The old receipt must relinquish IDs adopted by the repair before it becomes cleanup-eligible.');
        self::assertCount(1,$store->cleanupCandidates(1000,100));
        $store->cleaned('scope','partial');
        $store->compact(1000);
        self::assertSame([], $store->get('scope','partial')['resource_refs']);
        self::assertFalse($store->reserve($row),'Compaction preserves the operation key.');
    }
    public function testAPartialRepairTransfersSnapshotsBeforeItsOldReceiptCanBeCleaned(): void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        $connection=new ConnectionContext();$connection->set(4);$now=1000;
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturnCallback(static function()use(&$now):int{return $now;});
        $journal=new OperationJournal($store,new PlanState((new InMemoryConfig())->mock($this)),$connection,$clock);
        $args=['account_id'=>4];$p=$journal->plan('mail_write_draft','alice',$args,['plan_state'=>'proof']);
        $journal->execute('mail_write_draft','alice',$args+['plan_state'=>'proof','operation_id'=>$p['operation_id']],function()use($journal):array{
            $journal->checkpoint(['staged'=>[['attachment_id'=>9,'file_id'=>50,'sha256'=>'hash']]]);
            throw new \RuntimeException('Staging interrupted');
        });
        $now=100000;
        $repair=['account_id'=>4,'repair_of_operation_id'=>$p['operation_id']];
        $next=$journal->plan('mail_write_draft','alice',$repair,['plan_state'=>'fresh']);
        $result=$journal->execute('mail_write_draft','alice',$repair+['operation_id'=>$next['operation_id'],'plan_state'=>'fresh'],function()use($journal,$p):array{
            $journal->claimRepair('alice',$p['operation_id']);
            $refs=$journal->repairResources('alice',$p['operation_id']);$journal->checkpoint($refs);
            throw new \RuntimeException('Second file interrupted');
        });
        self::assertSame('partial',$result['operation']['state']);
        self::assertSame([9],array_column($journal->repairResources('alice',$next['operation_id'])['staged'],'attachment_id'));
        $expired=$store->cleanupCandidates($now-86400,100);
        self::assertCount(1,$expired);
        self::assertSame('repaired',$expired[0]['state']);
        self::assertSame([],$expired[0]['resource_refs']['staged'],'Cleanup of the old receipt cannot touch the new repair snapshots.');
    }
    public function testInterleavedDuplicateConfirmationProducesOneNativeEffect(): void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);
        $connection=new ConnectionContext();$connection->set(4);
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $proof=new PlanState((new InMemoryConfig())->mock($this));
        $a=new OperationJournal(new OperationsMapper($db->connection()),$proof,$connection,$clock);
        $b=new OperationJournal(new OperationsMapper($db->connection()),$proof,$connection,$clock);
        $args=['account_id'=>4,'subject'=>'Test'];
        $plan=$a->plan('mail_write_draft','alice',$args,['plan_state'=>'proof']);
        $args+=['operation_id'=>$plan['operation_id'],'plan_state'=>'proof'];
        $writes=0;
        $native=function() use (&$writes): array {$writes++;return ['draft'=>['id'=>77]];};
        $db->beforeStatement=function() use ($b,$args,$native): void {$b->execute('mail_write_draft','alice',$args,$native);};
        $result=$a->execute('mail_write_draft','alice',$args,$native);
        self::assertSame(1,$writes);
        self::assertTrue($result['operation']['replayed']);
        self::assertSame(77,$result['operation']['resource_refs']['draft_id']);
    }
    public function testAccountRemovalAndDraftUpdatesShareAnAccountReservation(): void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);
        $store=new OperationsMapper($db->connection());$context=new ConnectionContext();
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $proof=new PlanState((new InMemoryConfig())->mock($this));
        $remove=new OperationJournal($store,$proof,$context,$clock);$draft=new OperationJournal($store,$proof,$context,$clock);
        $a=['account_id'=>4];$p=$remove->plan('mail_delete_account','alice',$a,['plan_state'=>'delete']);
        $b=['account_id'=>4,'draft_id'=>7];$q=$draft->plan('mail_write_draft','alice',$b,['plan_state'=>'draft']);
        $blocked=false;$writes=0;
        $remove->execute('mail_delete_account','alice',$a+['operation_id'=>$p['operation_id'],'plan_state'=>'delete'],function()use($draft,$b,$q,&$blocked,&$writes):array{
            try{$draft->execute('mail_write_draft','alice',$b+['operation_id'=>$q['operation_id'],'plan_state'=>'draft'],function()use(&$writes):array{$writes++;return [];});}
            catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){$blocked=true;}
            return ['account_id'=>4];
        });
        self::assertTrue($blocked);self::assertSame(0,$writes);
    }

    public function testReplayAuthorizationAlsoRunsWhenAConcurrentReservationWins(): void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $proof=new PlanState((new InMemoryConfig())->mock($this));$context=new ConnectionContext();
        $a=new OperationJournal($store,$proof,$context,$clock);$b=new OperationJournal($store,$proof,$context,$clock);
        $args=['account_id'=>4];$p=$a->plan('mail_complete_account_setup','alice',$args,['plan_state'=>'proof']);
        $args+=['operation_id'=>$p['operation_id'],'plan_state'=>'proof'];$effects=0;$guards=0;
        $native=function()use($b,&$effects):array{$effects++;$b->checkpoint(['source_tool'=>'mail_add_account']);return ['account_id'=>4];};
        $db->beforeStatement=function()use($b,$args,$native):void{$b->execute('mail_complete_account_setup','alice',$args,$native);};
        try{$a->execute('mail_complete_account_setup','alice',$args,static fn():array=>throw new \LogicException('Duplicate effect'),function(array $receipt)use(&$guards):void{
            $guards++;self::assertSame('mail_add_account',$receipt['source_tool']);throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Revoked');
        });self::fail('Concurrent replay bypassed its current authorization');}catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){}
        self::assertSame(1,$effects);self::assertSame(1,$guards);
    }

}
