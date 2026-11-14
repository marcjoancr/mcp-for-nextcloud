<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Comments\FormsRecoveredCommentsReader;
use OCA\Mcp\Modules\Forms\Comments\FormsRecoveredCommentsCursor;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Support\OperationActors;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;
final class FormsRecoveredCommentsReaderTest extends TestCase {
    private function reader():FormsRecoveredCommentsReader {
        $connection=new ConnectionContext();$connection->set(12);
        return new FormsRecoveredCommentsReader(new FormsRecoveredCommentsCursor(new PlanState((new InMemoryConfig())->mock($this)),new OperationActors(),$connection));
    }
    private function refs():array {
        return ['form_id'=>4,'bulk_id'=>str_repeat('a',64),'bulk_action'=>'forms_restore',
            'bulk_authority'=>['tool'=>'forms_restore','required_operations'=>['restore'],'files_operations'=>['create']]];
    }
    private function guard():object {
        return new class {
            public bool $allowed=true;
            public bool $badCursor=false;
            public bool $foreignArchive=false;
            public function bulkStatus(string $uid,string $id,array $origin):array {
                return ['bulk_id'=>$id,'state'=>'completed','phase'=>'complete','version'=>7,'progress'=>['done'=>3,'total'=>3],
                    'references'=>['form_id'=>4,'restored_form_id'=>8,'backup_format'=>'bulk_v1'],'retained_files'=>[],
                    'next'=>false,'phases'=>['database'=>'committed','files'=>'not_applicable','events'=>[]]];
            }
            public function bulkCommentsPage(string $uid,string $id,array $origin,int $cursor=0,int $limit=50):array {
                if(!$this->allowed||($origin['form_id']??null)!==8)throw new ToolFailure('Current native source/restored comment authority denied.');
                $rows=[];$prefix='archive:'.$id.':';
                for($i=1;$i<=3;$i++)if($i>$cursor)$rows[]=['id'=>$prefix.$i,'source_id'=>$i,'parent_id'=>$i===1?null:$prefix.'1','root_id'=>$prefix.'1',
                    'author'=>['type'=>'users','id'=>'old-author','identity_status'=>'unverified_historical','principal'=>'private'],
                    'message'=>'Historical '.$i,'verb'=>'comment','created_at'=>'2026-11-01 12:00:00','historical'=>true,'private'=>'private'];
                $rows=array_slice($rows,0,$limit);$last=$rows===[]?$cursor:$rows[array_key_last($rows)]['source_id'];$more=$last<3;
                return ['items'=>$rows,'total'=>3,'next_cursor'=>$more?($this->badCursor?$last+1:$last):null,'complete'=>!$more,'archive_id'=>$this->foreignArchive?str_repeat('b',64):$id,'restored_form_id'=>8];
            }
        };
    }
    public function testRecoveredCommentsPageCompletelyWithoutLiveAuthorLookupOrPrivateProof():void {
        $reader=$this->reader();$guard=$this->guard();$refs=$this->refs();$factory=static fn(int $form,array $authority):array=>['form_id'=>$form];
        $snapshot=$reader->snapshot('alice','restore-operation',$refs,$guard,[],$factory);
        $cursor=null;$ids=[];
        do {
            $page=$reader->read('alice','restore-operation',$snapshot,$cursor,1,$guard);
            $ids=array_merge($ids,array_column($page['comments'],'id'));$cursor=$page['nextCursor'];
            self::assertTrue($page['read_only']);self::assertSame(8,$page['restored_form_id']);
            self::assertStringNotContainsString('private',json_encode($page));
            self::assertSame('unverified_historical',$page['comments'][0]['author']['identity_status']);
        } while($cursor!==null);
        self::assertSame(['archive:'.str_repeat('a',64).':1','archive:'.str_repeat('a',64).':2','archive:'.str_repeat('a',64).':3'],$ids);
    }
    public function testCurrentNativeAuthorityIsRequiredAgainAfterTheSnapshot():void {
        $reader=$this->reader();$guard=$this->guard();$snapshot=$reader->snapshot('alice','restore-operation',$this->refs(),$guard,[],static fn(int $id,array $a):array=>['form_id'=>$id]);
        $guard->allowed=false;$this->expectException(ToolFailure::class);
        $reader->read('alice','restore-operation',$snapshot,null,50,$guard);
    }
    public function testNativeArchiveContinuationCannotSkipAComment():void {
        $reader=$this->reader();$guard=$this->guard();$snapshot=$reader->snapshot('alice','restore-operation',$this->refs(),$guard,[],static fn(int $id,array $a):array=>['form_id'=>$id]);
        $guard->badCursor=true;$this->expectException(ToolFailure::class);
        $reader->read('alice','restore-operation',$snapshot,null,1,$guard);
    }
    public function testAnArchivePageCannotBeReplacedWithAnotherRecovery():void {
        $reader=$this->reader();$guard=$this->guard();$snapshot=$reader->snapshot('alice','restore-operation',$this->refs(),$guard,[],static fn(int $id,array $a):array=>['form_id'=>$id]);
        $guard->foreignArchive=true;$this->expectException(ToolFailure::class);
        $reader->read('alice','restore-operation',$snapshot,null,50,$guard);
    }
    public function testAnotherOriginalActionCannotAuthorizeTheHistoricalReader():void {
        $refs=$this->refs();$refs['bulk_action']='forms_delete_form';$refs['bulk_authority']=['tool'=>'forms_delete_form','required_operations'=>['delete_form'],'files_operations'=>[]];
        $this->expectException(ToolFailure::class);
        $this->reader()->snapshot('alice','restore-operation',$refs,$this->guard(),[],static fn(int $id,array $a):array=>['form_id'=>$id]);
    }
}
