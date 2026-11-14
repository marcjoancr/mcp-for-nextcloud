<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkWorkflow;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkImpactCursor;
use OCA\Mcp\Modules\Forms\Presentation\FormsProjection;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Support\OperationActors;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;
final class FormsHistoricalRecoveryWorkflowTest extends TestCase {
    private function workflow():FormsBulkWorkflow {
        $connection=new ConnectionContext();$connection->set(12);
        return new FormsBulkWorkflow(new FormsBulkImpactCursor(new PlanState((new InMemoryConfig())->mock($this)),new OperationActors(),$connection));
    }
    private function item():array {return ['file_id'=>91,'availability'=>'historical_unavailable','action'=>'unavailable_history','recovery'=>'historical_bytes_unavailable','path'=>null,'name'=>null,'size'=>null,'etag'=>null,'owner'=>'alice','unavailable_cause'=>'unverified'];}
    private function guard(array $item):object {return new class($item) {
        public function __construct(private array $item) {}
        public function bulkSnapshot(string $uid,string $tool,array $input):array {return ['revision'=>'b1:42:'.str_repeat('a',64),'form'=>['id'=>4,'ownerId'=>$uid],'counts'=>['files'=>1,'managed_shares'=>0],'historical_unavailable_files'=>1,'file_manifest'=>['files_share'=>false],'destination'=>null,'recovery'=>'sealed_current_state_with_unavailable_history','folders'=>'retained','linked_outputs'=>'retained'];}
        public function bulkPreviewPage(string $uid,string $tool,array $input,string $revision,string $kind,int $cursor,int $limit):array {return ['items'=>$kind==='files'?[$this->item]:[],'total'=>$kind==='files'?1:0,'next_cursor'=>null,'complete'=>true,'revision'=>$revision];}
    };}
    private function capture():\Closure {return static fn():array=>['tool'=>'forms_delete_form','operations'=>['delete_form'],'references'=>[]];}
    public function testRestorePrefixBindsExactNativeFormatAndRequiresSpecificQualification():void {
        $id=str_repeat('a',64);$native=['bulk_lifecycle'=>true,'bulk_backup_formats'=>[1,2],'bulk_history_format'=>'bulk_history_v2'];
        self::assertTrue(FormsBulkWorkflow::wantsBulk('forms_restore',['backup_id'=>'bulk_history_v2:'.$id],$native));
        self::assertSame(['backup_id'=>$id,'backup_format'=>2],FormsBulkWorkflow::nativeInput('forms_restore',['backup_id'=>'bulk_history_v2:'.$id]));
        self::assertSame(['backup_id'=>$id],FormsBulkWorkflow::nativeInput('forms_restore',['backup_id'=>'bulk_v1:'.$id]));
        $this->expectException(ToolFailure::class);FormsBulkWorkflow::wantsBulk('forms_restore',['backup_id'=>'bulk_history_v2:'.$id],['bulk_lifecycle'=>true]);
    }
    public function testHistoricalInspectionShowsLimitationWithoutResolvingAbsentNodeAsCurrentFile():void {
        $wf=$this->workflow();$guard=$this->guard($this->item());$files=static function(array $origin,string $tool,array $manifest):void{self::assertSame([],$manifest['files']??[],'Historical ID reached current node authorization.');};
        $snapshot=$wf->preview('alice','forms_delete_form',['id'=>4],$guard,$this->capture(),$files);
        self::assertSame(1,$snapshot['impact']['bulk']['historical_unavailable_files']);
        self::assertFalse($snapshot['impact']['bulk']['recovery_bytes_complete']);
        self::assertSame('whole_form_with_unavailable_history',$snapshot['impact']['bulk']['recovery_scope']);
        $page=$wf->inspect('alice',$snapshot['impact']['bulk']['collections']['files']['cursor'],50,$guard,$this->capture(),$files);
        $out=FormsProjection::read('forms_read_operation_impact',$page,[]);
        self::assertSame('historical_unavailable',$out['items'][0]['availability']);self::assertSame('unverified',$out['items'][0]['unavailable_cause']);
    }
    public function testMalformedUnavailableMarkerCannotBypassCurrentFileAuthorization():void {
        foreach([['path'=>'/still/live'],['action'=>'delete'],['recovery'=>'complete'],['unavailable_cause'=>'verified'],['availability'=>'unknown']] as $change) {
            try{$this->workflow()->preview('alice','forms_delete_form',['id'=>4],$this->guard(array_replace($this->item(),$change)),$this->capture(),static function():void{});self::fail('Malformed absence marker admitted.');}
            catch(ToolFailure){self::assertTrue(true);}
        }
    }
    public function testUnavailableCountCannotHideAnOrdinaryLiveFile():void {
        $this->expectException(ToolFailure::class);
        $this->workflow()->preview('alice','forms_delete_form',['id'=>4],$this->guard(['file_id'=>91,'name'=>'live.txt','action'=>'delete']),$this->capture(),static function(array $origin,string $tool,array $manifest):void{self::assertSame([['file_id'=>91]],$manifest['files']);});
    }
    public function testHistoricalOutcomeCannotClaimCompleteBytesOrAContradictoryCount():void {
        foreach([['bytes_complete'=>true],['historical_unavailable'=>0]] as $change) {
            $bad=$this->dto();$bad['retained_files']=array_replace($bad['retained_files'],$change);
            try{FormsBulkWorkflow::outcome($bad,'forms_restore');self::fail('Inconsistent byte evidence accepted.');}catch(ToolFailure){self::assertTrue(true);}
        }
    }
    private function dto():array {return ['bulk_id'=>str_repeat('b',64),'state'=>'completed','phase'=>'complete','version'=>7,'progress'=>['done'=>1,'total'=>1],
        'historical_unavailable_files'=>1,'retained_files'=>['historical_unavailable'=>1,'bytes_complete'=>false],
        'references'=>['form_id'=>4,'backup_id'=>str_repeat('b',64),'backup_format'=>'bulk_history_v2','recovery_scope'=>'whole_form_with_unavailable_history'],
        'next'=>false,'phases'=>['database'=>'committed','files'=>'not_applicable','events'=>[]]];}
    public function testOutcomeKeepsDistinctBackupPrefixAndHonestByteRecoveryStatus():void {
        foreach(['forms_delete_form','forms_restore'] as $tool){$out=FormsBulkWorkflow::outcome($this->dto(),$tool);
            self::assertSame('bulk_history_v2:'.str_repeat('b',64),$out['references']['backup_id']);
            $projected=FormsProjection::mutation($out['data'],$tool);self::assertSame(1,$projected['historical_unavailable_files']);self::assertFalse($projected['retained_files']['bytes_complete']);
        }
    }
    public function testHistoryFormatCannotClaimCompleteWholeFormBytes():void {
        $bad=$this->dto();$bad['references']['recovery_scope']='whole_form';
        $this->expectException(ToolFailure::class);FormsBulkWorkflow::outcome($bad,'forms_delete_form');
    }
}
