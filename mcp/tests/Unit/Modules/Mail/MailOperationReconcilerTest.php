<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Tests\Support\Mail\MailSettingsFixture;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCA\Mcp\Db\{OperationsMapper,MailSetupWorkflowsMapper};
use OCA\Mcp\Migration\{Version002700Date20261107000000,Version002700Date20261108000000};
use OCA\Mcp\Mcp\Operations\{OperationJournal,BeforeEffectFailure};
use OCA\Mcp\Modules\Mail\Operations\{MailOperationReader,MailOperationReconciler};
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class MailOperationReconcilerTest extends TestCase {
    use MailSettingsFixture;
    private function setupCase(array $refs, string $state='uncertain', string $tool='mail_add_alias'): array {
        [$gateway,,,, $proof]=$this->fixture();
        $sql=new SqliteDatabase($this,[new Version002700Date20261107000000(),new Version002700Date20261108000000()]);
        $store=new OperationsMapper($sql->connection());$context=new ConnectionContext();$context->set(4);
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(2000);
        $journal=new OperationJournal($store,$proof,$context,$clock);$access=(object)['denied'=>[]];
        $allows=static fn(string $uid,string $tool):bool=>!in_array($tool,$access->denied,true);
        $reader=new MailOperationReader($journal,$allows);
        $service=new MailOperationReconciler($store,new MailSetupWorkflowsMapper($sql->connection()),$gateway,$journal,$reader,$proof,$clock,$sql->connection(),$this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailJournalStorage::class));
        $old=['scope_key'=>$journal->scopeKey('alice'),'actor_uid'=>'alice','operation_id'=>'original','tool_name'=>$tool,'effect_digest'=>'effect','target_key'=>hash('sha256',"alice\0account:4"),'state'=>$state,'resource_refs'=>['account_id'=>4]+$refs,'created_at'=>100,'updated_at'=>100];
        $store->reserve($old);return [$service,$journal,$store,$old,$context,$sql,$reader,$access];
    }
    public function testProvenLocalCompletionReconcilesOnceWithoutRepeatingTheEffect():void {
        [$s,$j,$store,$old]=$this->setupCase(['alias_id'=>7,'local_completed'=>true]);
        $args=['account_id'=>4,'target_operation_id'=>'original'];$p=$j->plan('mail_reconcile_operation','alice',$args,$s->preview($args,'alice'));
        $a=$args+['operation_id'=>$p['operation_id'],'plan_state'=>$p['plan_state']];
        $result=$j->execute('mail_reconcile_operation','alice',$a,fn():array=>$s->write($a,'alice'));
        self::assertSame('succeeded',$result['operation']['state']);self::assertSame('succeeded',$j->get('alice','original')['state']);
        self::assertNull($store->get($old['scope_key'],'original')['target_key']);
        self::assertFalse($store->reserve($old),'Original non-replay key must survive');
        self::assertTrue($j->execute('mail_reconcile_operation','alice',$a,static fn():array=>throw new \LogicException('Never reconcile twice'))['operation']['replayed']);
    }
    public function testAbsenceAgeAndOpaqueResourceIdWithoutCompletionProofCannotReleaseAClaim():void {
        [$s,$j,$store,$old]=$this->setupCase(['alias_id'=>7]);
        try{$s->preview(['account_id'=>4,'target_operation_id'=>'original'],'alice');self::fail('An ID is not proof of completion');}catch(BeforeEffectFailure){}
        self::assertSame($old['target_key'],$store->get($old['scope_key'],'original')['target_key']);
        self::assertSame('uncertain',$j->get('alice','original')['state']);
    }
    public function testForeignConnectionAndAccountCannotInspectOrReconcileTheReceipt():void {
        [$s,$j,$store,$old,$context]=$this->setupCase(['alias_id'=>7,'local_completed'=>true]);
        $context->set(8);
        try{$s->preview(['account_id'=>4,'target_operation_id'=>'original'],'alice');self::fail('Foreign connection admitted');}catch(BeforeEffectFailure){}
        $context->set(4);
        try{$s->preview(['account_id'=>9,'target_operation_id'=>'original'],'alice');self::fail('Foreign account admitted');}catch(BeforeEffectFailure){}
        self::assertSame('uncertain',$store->get($old['scope_key'],'original')['state']);
    }
    public function testStaleWholeReceiptCannotOverwriteNewerEvidence():void {
        [$s,$j,$store,$old,,$sql]=$this->setupCase(['alias_id'=>7,'local_completed'=>true]);
        $sql->dbal->update('mcp_operations',['resource_refs'=>json_encode(['account_id'=>4,'alias_id'=>8,'local_completed'=>true])],['operation_id'=>'original']);
        self::assertFalse($store->reconcile($old,'succeeded',$old['resource_refs'],2000));
        self::assertSame(8,$store->get($old['scope_key'],'original')['resource_refs']['alias_id']);
    }
    public function testOnlyCompletedNativePhaseCanBeReconciledAndSourceRevocationHidesItsReceipt():void {
        $descriptor=['account_id'=>4,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','profile_revision'=>'fixture','cache_revision'=>'fixture','flag'=>'seen','value'=>true];
        foreach(['cache_pending','completed'] as $phase){
            [$s,$j,$store,$old,,,$reader,$access]=$this->setupCase(['mutation'=>['phase'=>$phase,'descriptor'=>$descriptor,'remote'=>['outcome'=>'verified'],'cache'=>['state'=>'updated']]],'running','mail_set_message_flag');
            $args=['account_id'=>4,'target_operation_id'=>'original'];
            if($phase==='cache_pending'){
                try{$s->preview($args,'alice');self::fail('Incomplete native write reconciled');}catch(BeforeEffectFailure){self::assertSame('running',$store->get($old['scope_key'],'original')['state']);}
                continue;
            }
            $p=$j->plan('mail_reconcile_operation','alice',$args,$s->preview($args,'alice'));$a=$args+['operation_id'=>$p['operation_id'],'plan_state'=>$p['plan_state']];
            $j->execute('mail_reconcile_operation','alice',$a,fn():array=>$s->write($a,'alice'));
            $access->denied=['mail_set_message_flag'];
            $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$reader->get('alice',$p['operation_id']);
        }
    }
    public function testCompletedSetupBindingCanRecoverItsInterruptedCompletionReceipt():void {
        [$s,$j,$store,$old,,$sql]=$this->setupCase(['source_operation_id'=>'setup','source_tool'=>'mail_configure_connection'],'uncertain','mail_complete_account_setup');
        $flow=new MailSetupWorkflowsMapper($sql->connection());$flow->create($old['scope_key'],'setup','configure_connection',4,[],'intent','before',1,3000);
        self::assertTrue($flow->claim($old['scope_key'],'setup','original',100));self::assertTrue($flow->complete($old['scope_key'],'setup','original',4));
        $store->reserve(array_replace($old,['operation_id'=>'setup','tool_name'=>'mail_configure_connection','target_key'=>null,'state'=>'succeeded','resource_refs'=>['account_id'=>4,'native_state'=>'observed_in_mail']]));
        $args=['account_id'=>4,'target_operation_id'=>'original'];$p=$j->plan('mail_reconcile_operation','alice',$args,$s->preview($args,'alice'));$a=$args+['operation_id'=>$p['operation_id'],'plan_state'=>$p['plan_state']];
        $j->execute('mail_reconcile_operation','alice',$a,fn():array=>$s->write($a,'alice'));self::assertSame('succeeded',$j->get('alice','original')['state']);
    }
    public function testSucceededExplicitRepairTransfersOnlyItsVerifiedSnapshots():void {
        $snap=['attachment_id'=>7,'file_id'=>9,'sha256'=>'integrity'];
        [$s,$j,$store,$old]=$this->setupCase(['staged'=>[$snap]],'repairing','mail_write_draft');
        $store->reserve(array_replace($old,['operation_id'=>'repair','target_key'=>null,'state'=>'succeeded','resource_refs'=>['account_id'=>4,'draft_id'=>8,'repair_source_operation_id'=>'original','staged'=>[$snap]]]));
        $args=['account_id'=>4,'target_operation_id'=>'original','evidence_operation_id'=>'repair'];$p=$j->plan('mail_reconcile_operation','alice',$args,$s->preview($args,'alice'));$a=$args+['operation_id'=>$p['operation_id'],'plan_state'=>$p['plan_state']];
        $j->execute('mail_reconcile_operation','alice',$a,fn():array=>$s->write($a,'alice'));
        self::assertSame('repaired',$j->get('alice','original')['state']);self::assertSame([],$store->get($old['scope_key'],'original')['resource_refs']['staged']);
    }

    public function testChangedTargetClaimCannotBeClearedByAStaleReceiptSnapshot():void {
        [$s,$j,$store,$old,,$sql]=$this->setupCase(['alias_id'=>7,'local_completed'=>true]);
        $sql->dbal->update('mcp_operations',['target_key'=>'newer-claim'],['operation_id'=>'original']);
        self::assertFalse($store->reconcile($old,'succeeded',$old['resource_refs'],2000));
        self::assertSame('newer-claim',$store->get($old['scope_key'],'original')['target_key']);
    }

}
