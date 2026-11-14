<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkWorkflow;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkImpactCursor;
use OCA\Mcp\Modules\Forms\Access\FormsAuthorization;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Support\OperationActors;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
final class FormsAuthoringBulkWorkflowTest extends TestCase {
    private function workflow():FormsBulkWorkflow {
        $connection=new ConnectionContext();$connection->set(12);$bodies=[];$i=0;$crypto=$this->createMock(ICrypto::class);
        $crypto->method('encrypt')->willReturnCallback(static function(string $plain)use(&$bodies,&$i):string{$key='cipher'.(++$i);$bodies[$key]=$plain;return $key;});
        $crypto->method('decrypt')->willReturnCallback(static function(string $cipher)use(&$bodies):string{return $bodies[$cipher];});
        return new FormsBulkWorkflow(new FormsBulkImpactCursor(new PlanState((new InMemoryConfig())->mock($this)),new OperationActors(),$connection,$crypto));
    }
    private function capture():\Closure {return static function(string $tool,array $input,array $snapshot):array {return ['tool'=>$tool,'operations'=>FormsAuthorization::operations($tool,$input,$snapshot),'references'=>['files_operations'=>FormsAuthorization::fileOperations($tool,$input,$snapshot)]];};}
    private function guard(bool $public=false):object {return new class($public) {
        public function __construct(private bool $public) {}
        public function bulkSnapshot(string $uid,string $tool,array $input):array {
            return ['revision'=>'b1:42:'.str_repeat('a',64),'form'=>['id'=>4,'title'=>'Old','ownerId'=>$uid],'counts'=>['files'=>0,'managed_shares'=>0,'answers'=>202],
                'authoring'=>$tool==='forms_update'?['fields_before'=>['title'=>'Old','description'=>'Old description','private'=>'secret'],'fields_after'=>$input['fields']]:
                    ($tool==='forms_delete_question'?['question_before'=>['id'=>7,'text'=>'File question','type'=>'file','private'=>'secret']]:['share_before'=>['id'=>7,'type'=>$this->public?3:0,'recipient'=>$this->public?null:'bob','public_link'=>$this->public,'permissions'=>['submit'],'token'=>'secret'],
                        'share_after'=>['permissions'=>['submit','results'],'token_changed'=>isset($input['token']),'private'=>'secret']]),
                'file_manifest'=>$this->public||in_array($tool,['forms_update','forms_delete_question'],true)?['files_share'=>false]:['files_share'=>true,'share_impact'=>['parent'=>['file_id'=>70,'path'=>'/private-parent'],'folder_ids'=>[71],'recipient'=>'bob','type'=>0,'create'=>true,'managed_shares'=>[]]],
                'destination'=>null,'recovery'=>$tool==='forms_delete_question'?'complete_sealed_before_effect':'non_restorable_effect_evidence','folders'=>'retained','linked_outputs'=>'retained'];
        }
        public function bulkPreviewPage(string $uid,string $tool,array $input,string $revision,string $kind,int $cursor,int $limit):array {return ['items'=>[],'total'=>0,'next_cursor'=>null,'complete'=>true,'revision'=>$revision];}
    };}
    public function testRoutingRequiresQualifiedDeclaredAuthoringAndPreservesOrdinaryVariants():void {
        $native=['bulk_lifecycle'=>true,'bulk_authoring_scale'=>true,'bulk_authoring_actions'=>['forms_update','forms_update_share','forms_unshare','forms_delete_question']];
        self::assertTrue(FormsBulkWorkflow::wantsBulk('forms_update',['id'=>4,'fields'=>['title'=>'New']],$native));
        self::assertFalse(FormsBulkWorkflow::wantsBulk('forms_update',['id'=>4,'fields'=>['description'=>'New']],$native));
        foreach(['forms_update_share','forms_unshare','forms_delete_question'] as $tool)self::assertTrue(FormsBulkWorkflow::wantsBulk($tool,['id'=>4],$native));
        self::assertFalse(FormsBulkWorkflow::wantsBulk('forms_delete_question',['id'=>4],array_replace($native,['bulk_authoring_scale'=>false])));
        foreach([['bulk_authoring_actions'=>[]],['bulk_lifecycle'=>false]] as $missing) {try{FormsBulkWorkflow::wantsBulk('forms_delete_question',['id'=>4],array_replace($native,$missing));self::fail('Missing bounded capability fell through.');}catch(ToolFailure){self::assertTrue(true);}}
    }
    public function testEmptyFolderSharePreviewRetainsOriginalShareVariantAndParentScope():void {
        $manifests=[];$snapshot=$this->workflow()->preview('alice','forms_update_share',['id'=>4,'share_id'=>7,'permissions'=>['submit','results']],$this->guard(),$this->capture(),static function(array $origin,string $tool,array $manifest)use(&$manifests):void{$manifests[]=$manifest;});
        self::assertSame(['share'],$snapshot['bulk_authority']['required_operations']);self::assertSame(['share'],$snapshot['bulk_authority']['files_operations']);
        self::assertTrue(count(array_filter($manifests,static fn(array $m):bool=>isset($m['share_impact']['parent'])))>0);
        $plan=\OCA\Mcp\Modules\Forms\Execution\FormsPlan::build('forms_update_share',['id'=>4,'share_id'=>7],$snapshot,'alice');
        self::assertSame('bob',$plan['before']['share']['recipient']);self::assertTrue($plan['impact']['files']['share_impact']['create']);
        self::assertStringNotContainsString('private-parent',json_encode($plan));self::assertStringNotContainsString('secret',json_encode($plan));
    }
    public function testEmptyShareCreationCannotApproveAnExcludedParent():void {
        $parentChecked=false;try{$this->workflow()->preview('alice','forms_update_share',['id'=>4,'share_id'=>7,'permissions'=>['results']],$this->guard(),$this->capture(),static function(array $origin,string $tool,array $manifest)use(&$parentChecked):void{if(isset($manifest['share_impact']['parent'])){$parentChecked=true;throw new ToolFailure('Parent excluded.');}});self::fail('Excluded parent approved.');}
        catch(ToolFailure){self::assertTrue($parentChecked,'The complete parent effect never reached current Files admission.');}
    }
    public function testPublicLinkVariantNeedsNoFilesShareOrUnrelatedBodies():void {
        $snapshot=$this->workflow()->preview('alice','forms_update_share',['id'=>4,'share_id'=>7,'token'=>'private-proposed-token'],$this->guard(true),$this->capture(),static function(array $origin,string $tool,array $manifest):void{self::assertSame([],$manifest['files']??[]);self::assertArrayNotHasKey('parent',$manifest['share_impact']??[]);});
        self::assertSame(['public_link'],$snapshot['bulk_authority']['required_operations']);self::assertSame([],$snapshot['bulk_authority']['files_operations']);
        $plan=\OCA\Mcp\Modules\Forms\Execution\FormsPlan::build('forms_update_share',['id'=>4,'share_id'=>7,'token'=>'private-proposed-token'],$snapshot,'alice');
        self::assertTrue($plan['before']['share']['public_link']);self::assertStringNotContainsString('private-proposed-token',json_encode($plan));self::assertStringNotContainsString('secret',json_encode($plan));
    }
    public function testSignedShareInspectionRetainsItsActualPublicLinkVariant():void {
        $workflow=$this->workflow();$args=['id'=>4,'share_id'=>7,'token'=>'private-proposed-token'];
        $snapshot=$workflow->preview('alice','forms_update_share',$args,$this->guard(true),$this->capture(),static function():void{});
        $page=$workflow->inspect('alice',$snapshot['impact']['bulk']['collections']['managed_shares']['cursor'],50,$this->guard(true),$this->capture(),static function():void{});
        self::assertSame('managed_shares',$page['collection']);self::assertFalse($page['hasMore']);self::assertSame([],$page['items']);
        self::assertStringNotContainsString('private-proposed-token',json_encode($page));
    }
    public function testPartialShareEvidenceNeverClaimsSuccessfulOrRestorableCompletion():void {
        $dto=$this->dto();$dto['state']='partial';$dto['phases']['files']='unknown';$out=FormsBulkWorkflow::outcome($dto,'forms_update_share');
        self::assertSame('partial',$out['state']);self::assertSame('unknown',$out['phases']['files']);self::assertArrayNotHasKey('backup_id',$out['references']);
        $dto['references']['evidence_id']=str_repeat('c',64);$this->expectException(ToolFailure::class);FormsBulkWorkflow::outcome($dto,'forms_update_share');
    }
    private function dto():array {return ['bulk_id'=>str_repeat('b',64),'state'=>'completed','phase'=>'complete','version'=>7,'progress'=>['done'=>1,'total'=>1],
        'references'=>['form_id'=>4,'backup_id'=>null,'backup_format'=>'authoring_effects_v1','evidence_id'=>str_repeat('b',64),'recovery_scope'=>'none'],'next'=>false,'phases'=>['database'=>'committed','files'=>'not_applicable','events'=>[]]];}
    public function testMetadataEvidenceNeverBecomesARestorableBackup():void {
        $out=FormsBulkWorkflow::outcome($this->dto(),'forms_update');self::assertSame('succeeded',$out['state']);self::assertArrayNotHasKey('backup_id',$out['references']);
        self::assertSame('none',$out['references']['recovery_scope']);self::assertSame(str_repeat('b',64),$out['references']['evidence_id']);
        $bad=$this->dto();$bad['references']['backup_id']=str_repeat('c',64);$this->expectException(ToolFailure::class);FormsBulkWorkflow::outcome($bad,'forms_update');
    }
    public function testTitleBeforeValuesAndQuestionRecoveryCountsAreExplicit():void {
        $wf=$this->workflow();$args=['id'=>4,'fields'=>['title'=>'New','description'=>'New description']];$snapshot=$wf->preview('alice','forms_update',$args,$this->guard(),$this->capture(),static function():void{});
        $plan=\OCA\Mcp\Modules\Forms\Execution\FormsPlan::build('forms_update',$args,$snapshot,'alice');self::assertSame('Old description',$plan['before']['description']);self::assertStringNotContainsString('secret',json_encode($plan));
        $args=['id'=>4,'question_id'=>7];$snapshot=$wf->preview('alice','forms_delete_question',$args,$this->guard(),$this->capture(),static function():void{});
        $plan=\OCA\Mcp\Modules\Forms\Execution\FormsPlan::build('forms_delete_question',$args,$snapshot,'alice');self::assertSame(7,$plan['before']['question']['id']);self::assertSame('recovery',$plan['impact']['bulk']['counts_scope']);self::assertSame('whole_form',$plan['impact']['bulk']['recovery_scope']);
    }
}
