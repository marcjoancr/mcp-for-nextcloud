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
    public function testOnlyFormsPartialEvidenceCanConvergeAndCannotBecomeNoEffect():void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        foreach(['forms_continue_operation','mail_write_draft'] as $tool) {
            $row=['scope_key'=>'scope','operation_id'=>$tool,'actor_uid'=>'alice','tool_name'=>$tool,'effect_digest'=>'digest',
                'target_key'=>null,'state'=>'running','resource_refs'=>[],'created_at'=>100,'updated_at'=>100];
            self::assertTrue($store->reserve($row));$store->finish('scope',$tool,'partial',['database'=>'committed'],100);
            $expected=$store->get('scope',$tool);
            self::assertFalse($store->reconcile($expected,'failed_before_effect',[],101));
            if($tool==='mail_write_draft') {
                self::assertFalse($store->reconcile($expected,'succeeded',[],101));continue;
            }
            self::assertTrue($store->reconcile($expected,'succeeded',['database'=>'committed'],101));
            self::assertFalse($store->reconcile($expected,'partial',[],102),'A stale proof cannot replace the accepted acknowledgement.');
            self::assertSame('succeeded',$store->get('scope',$tool)['state']);
        }
    }

    public function testUnknownBulkEvidenceRetainsTheTargetReservationAndNeverAuthorizesReplay():void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        $bulk=str_repeat('a',64);
        $refs=['actor_incarnation'=>'actor-one','connection_id'=>12,'native_key'=>str_repeat('b',64),'form_id'=>4,
            'bulk_id'=>$bulk,'bulk_version'=>7,'bulk_action'=>'forms_delete_form',
            'bulk_authority'=>['tool'=>'forms_delete_form','required_operations'=>['delete_form'],'files_operations'=>['edit']]];
        $row=['scope_key'=>'scope','operation_id'=>'bulk-step','actor_uid'=>'alice','tool_name'=>'forms_continue_operation','effect_digest'=>'approved',
            'target_key'=>'bulk-target','state'=>'running','resource_refs'=>$refs,'created_at'=>100,'updated_at'=>100];
        self::assertTrue($store->reserve($row));$store->finish('scope','bulk-step','uncertain',$refs,100);
        $old=$store->get('scope','bulk-step');
        $evidence=$refs+['backup_id'=>'bulk_v1:'.str_repeat('c',64),'backup_format'=>'bulk_v1','files'=>'unknown'];
        self::assertTrue($store->retainUncertain($old,$evidence,101));
        $fresh=$store->get('scope','bulk-step');
        self::assertSame('uncertain',$fresh['state']);self::assertSame('bulk-target',$fresh['target_key']);
        self::assertSame($evidence,$fresh['resource_refs']);
        self::assertFalse($store->reserve(array_replace($row,['operation_id'=>'duplicate'])));
        self::assertFalse($store->retainUncertain($old,$refs,102),'A stale receipt cannot erase accepted recovery evidence.');
        self::assertTrue($store->reconcile($fresh,'partial',$evidence,102));
        self::assertNull($store->get('scope','bulk-step')['target_key']);
        self::assertTrue($store->reserve(array_replace($row,['operation_id'=>'next-step'])));
        self::assertFalse($store->reserve($row),'The old operation ID remains a non-replay tombstone.');
    }
    public function testUncertainBulkEvidenceCannotChangeTheOriginalActorProtocolOrAuthority():void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        $refs=['actor_incarnation'=>'actor-one','connection_id'=>12,'native_key'=>str_repeat('b',64),'bulk_version'=>3,
            'bulk_id'=>str_repeat('a',64),'bulk_action'=>'forms_transfer',
            'bulk_authority'=>['tool'=>'forms_transfer','required_operations'=>['transfer'],'files_operations'=>['create']]];
        $row=['scope_key'=>'scope','operation_id'=>'bulk','actor_uid'=>'alice','tool_name'=>'forms_continue_operation','effect_digest'=>'approved',
            'target_key'=>'bulk-target','state'=>'running','resource_refs'=>$refs,'created_at'=>100,'updated_at'=>100];
        self::assertTrue($store->reserve($row));$old=$store->get('scope','bulk');
        foreach([
            ['native_key'=>str_repeat('c',64)],['connection_id'=>99],['actor_incarnation'=>'replacement'],['bulk_version'=>4],
            ['bulk_id'=>str_repeat('d',64)],['bulk_action'=>'forms_delete_form'],
            ['bulk_authority'=>['tool'=>'forms_transfer','required_operations'=>['transfer'],'files_operations'=>[]]],
        ] as $change)self::assertFalse($store->retainUncertain($old,array_replace($refs,$change),101));
        self::assertSame($old,$store->get('scope','bulk'));
    }
    public function testInitialBulkRecoveryCanAdoptOnlyItsAdmittedProtocolWhileMailIsUnaffected():void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        $refs=['actor_incarnation'=>'actor-one','connection_id'=>12,'native_key'=>str_repeat('b',64),
            'bulk_authority'=>['tool'=>'forms_delete_form','required_operations'=>['delete_form'],'files_operations'=>['edit']]];
        foreach(['forms_delete_form','mail_send'] as $tool) {
            $row=['scope_key'=>'scope','operation_id'=>$tool,'actor_uid'=>'alice','tool_name'=>$tool,'effect_digest'=>'approved',
                'target_key'=>$tool,'state'=>'running','resource_refs'=>$refs,'created_at'=>100,'updated_at'=>100];
            self::assertTrue($store->reserve($row));$old=$store->get('scope',$tool);
            $evidence=$refs+['bulk_id'=>str_repeat('a',64),'bulk_action'=>'forms_delete_form','backup_id'=>'bulk_v1:'.str_repeat('c',64),'backup_format'=>'bulk_v1'];
            self::assertSame($tool==='forms_delete_form',$store->retainUncertain($old,$evidence,101));
            self::assertSame($tool,$store->get('scope',$tool)['target_key']);
        }
    }


    public function testFreshFormsHistoryRecoversASealedBackupWithoutUnlockingAnUnknownStep():void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        $connection=new ConnectionContext();$connection->set(12);$proof=new PlanState((new InMemoryConfig())->mock($this));
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $journal=new \OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal($store,$proof,$connection,$clock,new \OCA\Mcp\Tests\Support\OperationActors(),$store);
        $args=['id'=>4,'plan_state'=>'proof'];$plan=$journal->plan('forms_delete_form','alice',$args,['plan_state'=>'proof']);
        $args['operation_id']=$plan['operation_id'];
        $journal->execute('forms_delete_form','alice',$args,static function(string $key,\Closure $checkpoint):array {
            $checkpoint(['bulk_authority'=>['tool'=>'forms_delete_form','required_operations'=>['delete_form'],'files_operations'=>['edit']]]);
            return ['state'=>'pending','references'=>['bulk_id'=>str_repeat('a',64),'bulk_action'=>'forms_delete_form','form_id'=>4]];
        });
        $step=['target_operation_id'=>$args['operation_id'],'plan_state'=>'step'];
        $plan=$journal->plan('forms_continue_operation','alice',$step,['plan_state'=>'step']);$step['operation_id']=$plan['operation_id'];
        $effects=0;
        $journal->execute('forms_continue_operation','alice',$step,static function()use(&$effects):array {$effects++;throw new \RuntimeException('Lost native acknowledgement');},
            null,static fn():array=>['bulk_id'=>str_repeat('a',64),'bulk_version'=>3]);
        $fresh=new \OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal(new OperationsMapper($db->connection()),$proof,$connection,$clock,new \OCA\Mcp\Tests\Support\OperationActors(),new OperationsMapper($db->connection()));
        $receipt=$fresh->get('alice',$step['operation_id'],static function():void {},static function(string $key,string $tool,array $refs):array {
            self::assertSame(3,$refs['bulk_version']);
            return ['state'=>'uncertain','references'=>['bulk_id'=>str_repeat('a',64),'bulk_action'=>'forms_delete_form','backup_id'=>'bulk_v1:'.str_repeat('c',64),'backup_format'=>'bulk_v1']];
        });
        self::assertSame('uncertain',$receipt['state']);
        self::assertSame('bulk_v1:'.str_repeat('c',64),$receipt['references']['backup_id']??null);
        self::assertSame(1,$effects);self::assertFalse($receipt['safe_to_retry']);
        $next=['target_operation_id'=>$args['operation_id'],'plan_state'=>'next'];
        $plan=$fresh->plan('forms_cancel_operation','alice',$next,['plan_state'=>'next']);$next['operation_id']=$plan['operation_id'];
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $fresh->execute('forms_cancel_operation','alice',$next,static fn():array=>self::fail('Uncertain native step was unlocked'),
            null,static fn():array=>['bulk_id'=>str_repeat('a',64),'bulk_version'=>3]);
    }


    public function testAnUnknownInitialBulkReceiptCannotUnlockASeparateBulkControlTarget():void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        $connection=new ConnectionContext();$connection->set(12);$proof=new PlanState((new InMemoryConfig())->mock($this));
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $journal=new \OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal($store,$proof,$connection,$clock,new \OCA\Mcp\Tests\Support\OperationActors(),$store);
        $args=['id'=>4,'plan_state'=>'proof'];$plan=$journal->plan('forms_delete_form','alice',$args,['plan_state'=>'proof']);$args['operation_id']=$plan['operation_id'];
        $journal->execute('forms_delete_form','alice',$args,static function(string $key,\Closure $checkpoint):array {
            $checkpoint(['bulk_authority'=>['tool'=>'forms_delete_form','required_operations'=>['delete_form'],'files_operations'=>['edit']]]);
            throw new \RuntimeException('Lost initial native receipt');
        });
        $receipt=$journal->get('alice',$args['operation_id'],static function():void {},static fn():array=>[
            'state'=>'uncertain','references'=>['bulk_id'=>str_repeat('a',64),'bulk_action'=>'forms_delete_form','backup_id'=>'bulk_v1:'.str_repeat('c',64),'backup_format'=>'bulk_v1']]);
        self::assertSame('uncertain',$receipt['state']);self::assertArrayHasKey('backup_id',$receipt['references']);
        foreach(['forms_continue_operation','forms_cancel_operation'] as $tool) {
            $step=['target_operation_id'=>$args['operation_id'],'plan_state'=>'control'];
            $plan=$journal->plan($tool,'alice',$step,['plan_state'=>'control']);$step['operation_id']=$plan['operation_id'];$called=false;$denied=false;
            try {
                $journal->execute($tool,'alice',$step,static function()use(&$called):array {$called=true;return ['state'=>'succeeded'];},
                    null,static fn():array=>['bulk_id'=>str_repeat('a',64),'bulk_version'=>3]);
            } catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure) {$denied=true;}
            self::assertTrue($denied,'An unresolved initial bulk result admitted a separate control target.');
            self::assertFalse($called,'No native control may run while the initial result remains unknown.');
        }
    }

    public function testFreshJournalRetainsNewUncertainEvidenceWithoutReleasingItsClaim():void {
        foreach([
            ['forms_update',['edit'],'authoring_effects_v1','none'],
            ['forms_update_share',['public_link'],'authoring_effects_v1','none'],
            ['forms_delete_question',['edit_questions'],'bulk_history_v2','whole_form_with_unavailable_history'],
            ['forms_restore',['restore'],'bulk_history_v2','whole_form_with_unavailable_history'],
        ] as [$tool,$ops,$format,$scope]) {
            $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
            $connection=new ConnectionContext();$connection->set(12);$proof=new PlanState((new InMemoryConfig())->mock($this));
            $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
            $make=fn()=>new \OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal($store,$proof,$connection,$clock,new \OCA\Mcp\Tests\Support\OperationActors(),$store);
            $journal=$make();$args=['id'=>4,'plan_state'=>'proof'];$plan=$journal->plan($tool,'alice',$args,['plan_state'=>'proof']);$args['operation_id']=$plan['operation_id'];$effects=0;
            $journal->execute($tool,'alice',$args,static function(string $key,\Closure $checkpoint)use($tool,$ops,&$effects):array {
                $checkpoint(['bulk_authority'=>['tool'=>$tool,'required_operations'=>$ops,'files_operations'=>[]]]);
                $effects++;throw new \RuntimeException('Lost native acknowledgement.');
            });
            $bulk=str_repeat('a',64);$evidence=['bulk_id'=>$bulk,'bulk_action'=>$tool,'backup_format'=>$format,'recovery_scope'=>$scope,'evidence_id'=>$bulk];
            if($format==='bulk_history_v2')$evidence['backup_id']=$format.':'.str_repeat('b',64);
            $receipt=$make()->get('alice',$args['operation_id'],static function():void{},static fn():array=>['state'=>'uncertain','references'=>$evidence]);
            self::assertSame($format,$receipt['references']['backup_format']??null);self::assertSame($scope,$receipt['references']['recovery_scope']??null);
            self::assertFalse($receipt['safe_to_retry']);self::assertSame(1,$effects);
            $lookup=$proof->of('forms_scope_v1','alice',[12]);$old=$store->get($lookup,$args['operation_id']);
            self::assertNotNull($old['target_key']);self::assertSame('uncertain',$old['state']);
            self::assertFalse($store->reserve(array_replace($old,['operation_id'=>'repeat'])));
            $replayed=$make()->execute($tool,'alice',$args,static function()use(&$effects):array{$effects++;return [];});
            self::assertSame('uncertain',$replayed['operation']['state']);self::assertSame(1,$effects);
        }
    }
    public function testNewUncertainEvidenceRejectsWrongActionFormatScopeAndBackupPrefix():void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        foreach(['forms_update'=>'edit','forms_delete_question'=>'edit_questions'] as $tool=>$op) {
            $bulk=str_repeat('a',64);$metadata=$tool==='forms_update';
            $refs=['actor_incarnation'=>'actor','connection_id'=>12,'native_key'=>str_repeat('c',64),
                'bulk_authority'=>['tool'=>$tool,'required_operations'=>[$op],'files_operations'=>[]]];
            $row=['scope_key'=>'scope','operation_id'=>$tool,'actor_uid'=>'alice','tool_name'=>$tool,'effect_digest'=>'approved','target_key'=>$tool,'state'=>'running','resource_refs'=>$refs,'created_at'=>100,'updated_at'=>100];
            self::assertTrue($store->reserve($row));$old=$store->get('scope',$tool);
            $evidence=$refs+['bulk_id'=>$bulk,'bulk_action'=>$tool,'backup_format'=>$metadata?'authoring_effects_v1':'bulk_history_v2','recovery_scope'=>$metadata?'none':'whole_form_with_unavailable_history','evidence_id'=>$bulk];
            if(!$metadata)$evidence['backup_id']='bulk_history_v2:'.str_repeat('b',64);
            $changes=[['backup_format'=>'unknown'],['recovery_scope'=>'whole_form'],['evidence_id'=>str_repeat('d',64)],['backup_id'=>'bulk_v1:'.str_repeat('b',64)]];
            if($metadata)$changes[]=['backup_format'=>'bulk_history_v2','recovery_scope'=>'whole_form_with_unavailable_history'];
            else $changes[]=['backup_format'=>'authoring_effects_v1','recovery_scope'=>'none','backup_id'=>null];
            foreach($changes as $change)self::assertFalse($store->retainUncertain($old,array_replace($evidence,$change),101));
            self::assertSame($old,$store->get('scope',$tool));
            self::assertTrue($store->retainUncertain($old,$evidence,101));
        }
    }

    public function testDeliveryReceiptSurvivesCompactionWithoutContentOrPrivateArtifact():void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        $row=['scope_key'=>'scope','operation_id'=>'send','actor_uid'=>'alice','tool_name'=>'mail_send','effect_digest'=>'digest','target_key'=>null,'state'=>'running','resource_refs'=>[],'created_at'=>100,'updated_at'=>100];
        $store->reserve($row);$id=str_repeat('a',64);$store->finish('scope','send','succeeded',['delivery_id'=>$id,'source_tool'=>'mail_send','body'=>'private','artifact'=>'private'],100);
        $store->compact(1000);self::assertSame(['delivery_id'=>$id,'source_tool'=>'mail_send'],$store->get('scope','send')['resource_refs']);
    }
    public function testMailMaintenanceCannotEraseFormsRecoveryOrOriginalAuthority():void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        $row=['scope_key'=>'forms','operation_id'=>'change','actor_uid'=>'alice','tool_name'=>'forms_update','effect_digest'=>'digest','target_key'=>null,'state'=>'running','resource_refs'=>[],'created_at'=>100,'updated_at'=>100];
        $refs=['actor_incarnation'=>'stable','connection_id'=>12,'native_key'=>'key','required_operations'=>['edit'],'form_id'=>4,'backup_id'=>'recovery'];
        self::assertTrue($store->reserve($row));$store->finish('forms','change','succeeded',$refs,100);$store->compact(1000);
        self::assertSame($refs,$store->get('forms','change')['resource_refs']);
    }
    public function testMailCleanupCannotClaimExpireOrDiscardFormsPartialEvidence():void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($db->connection());
        $row=['scope_key'=>'forms','operation_id'=>'partial-form','actor_uid'=>'alice','tool_name'=>'forms_submit_response','effect_digest'=>'digest','target_key'=>null,'state'=>'running','resource_refs'=>[],'created_at'=>100,'updated_at'=>100];
        $refs=['actor_incarnation'=>'stable','native_key'=>'key','required_operations'=>['submit'],'form_id'=>4,'backup_id'=>'backup'];
        $store->reserve($row);$store->finish('forms','partial-form','partial',$refs,100);
        $mail=new \OCA\Mcp\Modules\Mail\Integration\MailServices($this->createMock(\OCP\App\IAppManager::class),static fn()=>throw new \LogicException('No Mail data may be resolved'),$this->createMock(\OCP\IUserManager::class));
        $time=$this->createMock(ITimeFactory::class);$time->method('getTime')->willReturn(4000000);
        (new \OCA\Mcp\Modules\Mail\Operations\MailOperationCleanup($store,$mail,$time))->run();
        self::assertSame('partial',$store->get('forms','partial-form')['state']);self::assertSame($refs,$store->get('forms','partial-form')['resource_refs']);
        self::assertFalse($store->claimPartial('forms','partial-form','cleaning'));
        $store->cleaned('forms','partial-form');self::assertSame($refs,$store->get('forms','partial-form')['resource_refs']);
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
        $journal=new OperationJournal($store,new PlanState((new InMemoryConfig())->mock($this)),$connection,$clock, new \OCA\Mcp\Tests\Support\OperationActors());
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
        $a=new OperationJournal(new OperationsMapper($db->connection()),$proof,$connection,$clock, new \OCA\Mcp\Tests\Support\OperationActors());
        $b=new OperationJournal(new OperationsMapper($db->connection()),$proof,$connection,$clock, new \OCA\Mcp\Tests\Support\OperationActors());
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
        $remove=new OperationJournal($store,$proof,$context,$clock, new \OCA\Mcp\Tests\Support\OperationActors());$draft=new OperationJournal($store,$proof,$context,$clock, new \OCA\Mcp\Tests\Support\OperationActors());
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
        $a=new OperationJournal($store,$proof,$context,$clock, new \OCA\Mcp\Tests\Support\OperationActors());$b=new OperationJournal($store,$proof,$context,$clock, new \OCA\Mcp\Tests\Support\OperationActors());
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
