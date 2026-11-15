<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use RuntimeException;
use stdClass;
use OCA\Mcp\Modules\Forms\Attachments\FormsFileImpact;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
final class FormsTransferFileImpactTest extends TestCase {
 private function fixture(int$n):object {
  $root=$this->createMock(IRootFolder::class);$folder=$this->createMock(Folder::class);$root->method('getUserFolder')->with('alice')->willReturn($folder);
  $state=(object)['hidden'=>null,'unreadable'=>null,'uncreatable'=>false];$sources=[];$copies=[];
  for($i=1;$i<=$n;$i++){
   foreach(['source','copy'] as$kind){$id=$kind==='source'?$i:10000+$i;$f=$this->createMock(File::class);$f->method('getId')->willReturn($id);$f->method('isReadable')->willReturnCallback(static fn()=>$state->unreadable!==$id);if($kind==='source')$sources[]=$f;else$copies[]=$f;}
  }
  $parent=$this->createMock(Folder::class);$parent->method('getId')->willReturn(99999);$parent->method('isReadable')->willReturn(true);$parent->method('isCreatable')->willReturnCallback(static fn()=>!$state->uncreatable);
  $folder->method('getById')->willReturnCallback(static fn(int$id)=>isset($sources[$id-1])?[$sources[$id-1]]:[]);
  $guard=$this->createMock(FilesAccessGuard::class);$guard->method('isVisible')->willReturnCallback(static fn($node)=>$node->getId()!==$state->hidden);
  $impact=new FormsFileImpact($root,$guard);$manifest=['files'=>array_map(static fn($f)=>['file_id'=>$f->getId()],$sources)];return(object)compact('impact','state','copies','parent','manifest');
 }
 public static function counts():array{return [[100],[101],[1000]];}
 #[DataProvider('counts')]
 public function testEverySourceCopyAndActualParentAreCheckedWithoutTruncation(int$n):void {
  $f=$this->fixture($n);$f->impact->assertTransferFiles('alice',$f->manifest,$f->copies,[$f->parent]);$f->state->hidden=$n;
  $error=null;try{$f->impact->assertTransferFiles('alice',$f->manifest,$f->copies,[$f->parent]);}catch(RuntimeException$e){$error=$e->getMessage();}self::assertSame('Forms file effect is outside the current Files access',$error);
 }
 public static function revocations():array{return [['copy_scope'],['copy_acl'],['parent_scope'],['parent_create']];}
 #[DataProvider('revocations')]
 public function testActualCopiesAndParentsUseCurrentResourcePermissions(string$which):void {
  $f=$this->fixture(3);$f->impact->assertTransferFiles('alice',$f->manifest,$f->copies,[$f->parent]);
  match($which){'copy_scope'=>$f->state->hidden=10003,'copy_acl'=>$f->state->unreadable=10003,'parent_scope'=>$f->state->hidden=99999,'parent_create'=>$f->state->uncreatable=true};
  $error=null;try{$f->impact->assertTransferFiles('alice',$f->manifest,$f->copies,[$f->parent]);}catch(RuntimeException$e){$error=$e->getMessage();}
  self::assertSame(str_starts_with($which,'copy')?'Forms transfer copy is outside the current Files access':'Forms destination is outside the current Files access',$error);
 }
 public function testOversizedOrMalformedCompositeRefusesBeforeNodeResolution():void {
  $root=$this->createMock(IRootFolder::class);$root->expects(self::never())->method('getUserFolder');$g=$this->createMock(FilesAccessGuard::class);$impact=new FormsFileImpact($root,$g);
  foreach([[array_fill(0,1001,$this->createMock(File::class)),[]],[[new stdClass()],[]],[[],[new stdClass()]]]as[$copies,$parents]){
   $error=null;try{$impact->assertTransferFiles('alice',['files'=>[]],$copies,$parents);}catch(RuntimeException$e){$error=$e->getMessage();}self::assertSame('Forms transfer resource projection is invalid',$error);
  }
 }
}
