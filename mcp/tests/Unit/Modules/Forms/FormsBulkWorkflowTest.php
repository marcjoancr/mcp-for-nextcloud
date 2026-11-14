<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkWorkflow;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkImpactCursor;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Support\OperationActors;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;
final class FormsBulkWorkflowTest extends TestCase {
    private FormsBulkWorkflow $workflow;
    private FormsBulkImpactCursor $cursor;
    protected function setUp():void {
        $connection=new ConnectionContext();$connection->set(12);
        $this->cursor=new FormsBulkImpactCursor(new PlanState((new InMemoryConfig())->mock($this)),new OperationActors(),$connection);
        $this->workflow=new FormsBulkWorkflow($this->cursor);
    }
    private function guard():object {return new class {
        public bool $stalled=false;public bool $truncated=false;public bool $duplicate=false;public int $shares=0;
        public function bulkSnapshot(string $uid,string $tool,array $input):array {
            return ['revision'=>'b1:42:'.str_repeat('a',64),'form'=>['id'=>4,'ownerId'=>'alice'],'counts'=>['files'=>101,'managed_shares'=>$this->shares],
                'file_manifest'=>['files_share'=>$this->shares>0],'destination'=>null,'recovery'=>'complete_sealed_before_effect','folders'=>'retained','linked_outputs'=>'retained'];
        }
        public function bulkPreviewPage(string $uid,string $tool,array $input,string $revision,string $kind,int $cursor,int $limit):array {
            if($kind==='managed_shares')return ['items'=>[],'total'=>0,'next_cursor'=>null,'complete'=>true,'revision'=>$revision];
            $items=[];if(!$this->stalled)for($id=$cursor+1;$id<=min(101,$cursor+$limit);$id++)$items[]=['file_id'=>$this->duplicate&&$cursor>0?1:$id,'name'=>'file-'.$id,'size'=>1,'etag'=>'etag-'.$id,'action'=>'delete'];
            $end=$cursor+count($items);$complete=$this->truncated||$end===101;
            return ['items'=>$items,'total'=>101,'next_cursor'=>$complete?null:$end,'complete'=>$complete,'revision'=>$revision];
        }
    };}
    private function capture():\Closure {return static fn(string $tool,array $input,array $snapshot):array=>['tool'=>$tool,'operations'=>['delete_form'],'references'=>[]];}
    public function testPreviewAdmitsAll101FilesAndOffersAnOpaqueInspectionCursor():void {
        $seen=[];
        $snapshot=$this->workflow->preview('alice','forms_delete_form',['id'=>4],$this->guard(),$this->capture(),
            static function(array $origin,string $tool,array $manifest)use(&$seen):void {foreach($manifest['files']??[] as $f)$seen[]=$f['file_id'];});
        self::assertSame(range(1,101),$seen);
        $collection=$snapshot['impact']['bulk']['collections']['files'];
        self::assertSame(101,$collection['total']);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$collection['digest']);
        $target=$this->cursor->verify('alice',$collection['cursor']);
        self::assertSame('forms_delete_form',$target['tool']);self::assertSame(0,$target['cursor']);
        self::assertArrayNotHasKey('items',$collection);
    }
    public function testAStalledNativePreviewPageCannotProduceApproval():void {
        $guard=$this->guard();$guard->stalled=true;
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->workflow->preview('alice','forms_delete_form',['id'=>4],$guard,$this->capture(),static function():void{});
    }
    public function testATruncatedNativePreviewCannotProduceApproval():void {
        $guard=$this->guard();$guard->truncated=true;
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->workflow->preview('alice','forms_delete_form',['id'=>4],$guard,$this->capture(),static function():void{});
    }
    public function testRepeatedNativeFileIdsCannotHideAnUninspectedFile():void {
        $guard=$this->guard();$guard->duplicate=true;
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->workflow->preview('alice','forms_delete_form',['id'=>4],$guard,$this->capture(),static function():void{});
    }
    public function testPrivateRestoreDoesNotRecreateSharesOrRequireAccessToDeletedSourceFiles():void {
        $guard=$this->guard();$guard->shares=4;
        $capture=static fn(string $tool,array $input,array $snapshot):array=>['tool'=>$tool,'operations'=>['restore'],
            'references'=>['files_operations'=>($snapshot['file_manifest']['files_share']??false)?['share']:[]]];
        $snapshot=$this->workflow->preview('alice','forms_restore',['backup_id'=>'bulk_v1:'.str_repeat('b',64)],$guard,$capture,
            static function():void {self::fail('Restore must read its authorized recovery, not deleted live file IDs.');});
        self::assertFalse($snapshot['file_manifest']['files_share']);self::assertSame([],$snapshot['bulk_authority']['files_operations']);
        self::assertSame(['total'=>0,'not_restored'=>true],$snapshot['impact']['bulk']['collections']['managed_shares']);
        self::assertArrayNotHasKey('managed_shares',$snapshot['impact']['bulk']['counts']);
    }
    public function testResponseDeletionDoesNotRequestUnchangedSharingAuthority():void {
        $guard=$this->guard();$guard->shares=4;
        $capture=static fn(string $tool,array $input,array $snapshot):array=>['tool'=>$tool,'operations'=>['delete_results'],
            'references'=>['files_operations'=>($snapshot['file_manifest']['files_share']??false)?['share']:[]]];
        $snapshot=$this->workflow->preview('alice','forms_delete_responses',['id'=>4],$guard,$capture,static function():void{});
        self::assertSame([],$snapshot['bulk_authority']['files_operations']);
        self::assertSame(['total'=>0,'not_changed'=>true],$snapshot['impact']['bulk']['collections']['managed_shares']);
        self::assertArrayNotHasKey('managed_shares',$snapshot['impact']['bulk']['counts']);
    }
    public function testInspectionUsesOneDeadlineAcrossRebuildAndRequestedPage():void {
        $time=0.0;$workflow=new FormsBulkWorkflow($this->cursor,static function()use(&$time):float{return $time;});
        $capture=static function(string $tool,array $input,array $snapshot)use(&$time):array {
            $time+=4.0;return ['tool'=>$tool,'operations'=>['delete_form'],'references'=>[]];
        };
        $files=static function()use(&$time):void {$time+=3.0;};
        $token=$this->cursor->create('alice','forms_delete_form',['id'=>4],'b1:42:'.str_repeat('a',64),'files',0);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $workflow->inspect('alice',$token,50,$this->guard(),$capture,$files);
    }
    public function testUnknownFilesCannotBeReportedAsCompleted():void {
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        FormsBulkWorkflow::outcome(['bulk_id'=>str_repeat('a',64),'state'=>'completed','phase'=>'complete','version'=>9,
            'progress'=>['done'=>1,'total'=>1],'references'=>['form_id'=>4],'next'=>false,
            'phases'=>['database'=>'committed','files'=>'unknown','events'=>[]]],'forms_delete_form');
    }
    public function testCompletedEvidenceKeepsRecoveryFormatAndDropsNativeAuthority():void {
        $out=FormsBulkWorkflow::outcome(['bulk_id'=>str_repeat('a',64),'operation_key'=>'private-key','origin'=>['proof'=>'private'],
            'state'=>'completed','phase'=>'complete','version'=>9,'progress'=>['done'=>101,'total'=>101],
            'references'=>['form_id'=>4,'backup_id'=>str_repeat('b',64),'restored_form_id'=>null,'backup_format'=>'bulk_v1'],
            'retained_files'=>['recovery'=>101,'copies'=>0,'evidence_id'=>str_repeat('a',64),'backup_sealed'=>true],
            'next'=>false,'phases'=>['database'=>'committed','files'=>'verified','events'=>[]]],'forms_delete_form');
        self::assertSame('succeeded',$out['state']);self::assertTrue($out['data']['complete']);
        self::assertSame('bulk_v1:'.str_repeat('b',64),$out['references']['backup_id']);
        self::assertStringNotContainsString('private',json_encode($out,JSON_THROW_ON_ERROR));
        self::assertSame('forms_delete_form',$out['references']['bulk_action']);
    }
}
