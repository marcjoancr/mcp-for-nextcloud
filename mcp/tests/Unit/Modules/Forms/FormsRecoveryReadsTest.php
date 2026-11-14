<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Integration\FormsRecoveryReads;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkWorkflow;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkImpactCursor;
use OCA\Mcp\Modules\Forms\Comments\FormsRecoveredCommentsReader;
use OCA\Mcp\Modules\Forms\Comments\FormsRecoveredCommentsCursor;
use OCA\Mcp\Modules\Forms\Presentation\FormsProjection;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Support\OperationActors;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;
final class FormsRecoveryReadsTest extends TestCase {
    private FormsBulkImpactCursor $cursors;
    private FormsRecoveryReads $reads;
    private array $captures=[];
    private bool $allowed=true;
    private function setupReads():void {
        $connection=new ConnectionContext();$connection->set(12);$proof=new PlanState((new InMemoryConfig())->mock($this));$actors=new OperationActors();
        $this->cursors=new FormsBulkImpactCursor($proof,$actors,$connection);
        $this->reads=new FormsRecoveryReads(new FormsBulkWorkflow($this->cursors),$this->cursors,new FormsRecoveredCommentsReader(new FormsRecoveredCommentsCursor($proof,$actors,$connection)));
    }
    private function capture():\Closure {
        return function(string $tool,array $args,array $snapshot):array {
            $this->captures[]=[$tool,$args,$snapshot];
            if(!$this->allowed)throw new ToolFailure('Current reader permission withdrawn.');
            return ['tool'=>$tool,'operations'=>[$tool==='forms_transfer'?'transfer':($tool==='forms_restore'?'restore':'read_operations')],
                'references'=>['form_id'=>$args['id']??null,'files_operations'=>($snapshot['file_manifest']['files_share']??false)?['share']:[],'bulk_authority'=>$snapshot['bulk_authority']??null]];
        };
    }
    private function history():\Closure {return static fn(string $op):array=>['bulk_id'=>str_repeat('b',64),'bulk_action'=>'forms_restore',
        'bulk_authority'=>['tool'=>'forms_restore','required_operations'=>['restore'],'files_operations'=>['create']]];}
    private function guard():object {return new class {
        public int $pages=0;public int $snapshots=0;public bool $changed=false;public array $commentOrigins=[];
        public function bulkSnapshot(string $uid,string $tool,array $args):array {++$this->snapshots;return ['revision'=>'b1:42:'.str_repeat($this->changed?'c':'a',64),'form'=>['id'=>4,'ownerId'=>$uid],
            'counts'=>['files'=>101,'managed_shares'=>0],'file_manifest'=>['files_share'=>true],'destination'=>null,'recovery'=>[],'folders'=>[],'linked_outputs'=>[]];}
        public function bulkPreviewPage(string $uid,string $tool,array $input,string $revision,string $kind,int $cursor,int $limit):array {
            ++$this->pages;$rows=[];if($kind==='files')for($i=$cursor+1;$i<=min(101,$cursor+$limit);$i++)$rows[]=['file_id'=>$i,'name'=>'file'.$i,'action'=>'copy','private'=>'hidden'];
            $last=$rows===[]?$cursor:$rows[array_key_last($rows)]['file_id'];return ['items'=>$rows,'total'=>$kind==='files'?101:0,'complete'=>$kind!=='files'||$last===101,'next_cursor'=>$kind==='files'&&$last<101?$last:null,'revision'=>$revision];
        }
        public function bulkStatus(string $uid,string $id,array $origin):array {return ['bulk_id'=>$id,'state'=>'completed','phase'=>'complete','version'=>7,'progress'=>['done'=>1,'total'=>1],
            'references'=>['restored_form_id'=>8],'next'=>false,'phases'=>['database'=>'committed','files'=>'not_applicable','events'=>[]]];}
        public function bulkCommentsPage(string $uid,string $id,array $origin,int $after,int $limit):array {
            $this->commentOrigins[]=$origin;if(($origin['references']['form_id']??null)!==8||($origin['references']['bulk_authority']['tool']??null)!=='forms_restore')throw new ToolFailure('Wrong comment authority.');
            return ['items'=>[],'total'=>0,'complete'=>true,'next_cursor'=>null,'archive_id'=>$id,'restored_form_id'=>8];
        }
    };}
    public function testImpactSnapshotsAreBoundedAndInspectionChecksAllFilesAndOriginalSharingAuthority():void {
        $this->setupReads();$guard=$this->guard();$args=['cursor'=>$this->cursors->create('alice','forms_transfer',['id'=>4,'owner_id'=>'bob'],'b1:42:'.str_repeat('a',64),'files',100),'limit'=>1];
        $snapshot=$this->reads->snapshot('alice','forms_read_operation_impact',$args,$guard,$this->history(),$this->capture());
        self::assertSame(0,$guard->pages,'A metadata snapshot must not repeat complete effect enumeration.');
        self::assertSame(['share'],$snapshot['bulk_authority']['files_operations']);$checked=[];
        $page=$this->reads->read('alice','forms_read_operation_impact',$args,$snapshot,$guard,$this->capture(),static function(array $origin,string $tool,array $manifest)use(&$checked):void{foreach($manifest['files']??[] as $file)$checked[$file['file_id']]=true;});
        self::assertCount(101,$checked);self::assertSame(101,$page['items'][0]['file_id']);self::assertFalse($page['hasMore']);
        self::assertStringNotContainsString('hidden',json_encode($page));
        $current=array_values(array_filter($this->captures,static fn(array $c):bool=>$c[0]==='forms_read_operation_impact'));
        self::assertGreaterThanOrEqual(3,count($current));self::assertSame(['share'],$current[array_key_last($current)][2]['bulk_authority']['files_operations']);
    }
    public function testImpactCannotReuseAStaleNativeRevision():void {
        $this->setupReads();$guard=$this->guard();$guard->changed=true;$args=['cursor'=>$this->cursors->create('alice','forms_transfer',['id'=>4,'owner_id'=>'bob'],'b1:42:'.str_repeat('a',64),'files',0)];
        $this->expectException(ToolFailure::class);$this->reads->snapshot('alice','forms_read_operation_impact',$args,$guard,$this->history(),$this->capture());
    }
    public function testCurrentImpactPermissionIsCheckedAgainDuringRead():void {
        $this->setupReads();$guard=$this->guard();$args=['cursor'=>$this->cursors->create('alice','forms_transfer',['id'=>4,'owner_id'=>'bob'],'b1:42:'.str_repeat('a',64),'files',0)];
        $snapshot=$this->reads->snapshot('alice','forms_read_operation_impact',$args,$guard,$this->history(),$this->capture());$this->allowed=false;
        $this->expectException(ToolFailure::class);$this->reads->read('alice','forms_read_operation_impact',$args,$snapshot,$guard,$this->capture(),static function():void{});
    }
    public function testArchiveUsesRestoredFormAndPreservesOriginalRecoveryAuthority():void {
        $this->setupReads();$guard=$this->guard();$args=['target_operation_id'=>'original-restore'];$operations=[];
        $history=function(string $operation)use(&$operations):array{$operations[]=$operation;return ($this->history())($operation);};
        $snapshot=$this->reads->snapshot('alice','forms_read_recovered_comments',$args,$guard,$history,$this->capture());
        $page=$this->reads->read('alice','forms_read_recovered_comments',$args,$snapshot,$guard,$this->capture(),static function():void{});
        self::assertSame(['original-restore'],$operations);self::assertSame(8,$page['restored_form_id']);self::assertTrue($page['read_only']);
        self::assertSame(['create'],$guard->commentOrigins[1]['references']['bulk_authority']['files_operations']);
    }
    public function testArchiveChecksCurrentReaderAgainAfterSnapshot():void {
        $this->setupReads();$guard=$this->guard();$args=['target_operation_id'=>'original-restore'];
        $snapshot=$this->reads->snapshot('alice','forms_read_recovered_comments',$args,$guard,$this->history(),$this->capture());$this->allowed=false;
        $this->expectException(ToolFailure::class);$this->reads->read('alice','forms_read_recovered_comments',$args,$snapshot,$guard,$this->capture(),static function():void{});
    }
    public function testPublicProjectionDropsPrivateReaderMetadata():void {
        $impact=FormsProjection::read('forms_read_operation_impact',['collection'=>'files','items'=>[['file_id'=>1,'private'=>'hidden']],'total'=>1,'hasMore'=>false,'nextCursor'=>null,'origin'=>'hidden'],[]);
        $comments=FormsProjection::read('forms_read_recovered_comments',['comments'=>[['id'=>'archive:id:1','author'=>['type'=>'users','id'=>'alice','identity_status'=>'unverified_historical','principal'=>'hidden'],'message'=>'Old','origin'=>'hidden']],'total'=>1,'restored_form_id'=>8,'read_only'=>true,'hasMore'=>false,'nextCursor'=>null,'origin'=>'hidden'],[]);
        self::assertStringNotContainsString('hidden',json_encode([$impact,$comments]));self::assertSame('Old',$comments['comments'][0]['message']);
    }
}
