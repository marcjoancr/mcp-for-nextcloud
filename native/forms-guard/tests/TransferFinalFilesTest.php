<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use OCP\Files\File;
use OCP\Files\Folder;
use OCA\Forms\Mcp\Service\TransferFinalFiles;
final class TransferFinalFilesTest extends TestCase {
 protected function setUp():void {require_once __DIR__.'/../src/Service/BulkDeadline.php';$p=__DIR__.'/../src/Service/TransferFinalFiles.php';self::assertFileExists($p);require_once $p;}
 private function fixture():object {
  $state=(object)['etag'=>'sealed','owner'=>'bob','bytes'=>'original','reads'=>0,'revoke'=>false,'authorized'=>0,'targetRevoked'=>false,'events'=>[],'readMutation'=>null,'sourceOwner'=>'alice','readable'=>true,'creatable'=>true,'targetMutates'=>null,'authMutates'=>null,'parentId'=>3];$cache=['storage'=>1,'path'=>'cache','size'=>8,'etag'=>'sealed'];$hash=hash('sha256',json_encode($cache,JSON_THROW_ON_ERROR));
  $records=[['source'=>['id'=>1,'path'=>'/alice/files/a','owner'=>'alice','storage'=>'home::alice','etag'=>'sealed','size'=>8,'sha256'=>hash('sha256','original'),'cache'=>$hash],'copy'=>['id'=>2,'path'=>'/bob/files/a','owner'=>'bob','storage'=>'home::bob','etag'=>'sealed','size'=>8,'cache'=>$hash],'parent_id'=>3]];
  $parents=[['id'=>3,'path'=>'/bob/files','owner'=>'bob','storage'=>'home::bob']];
  $folder=function()use($state):Folder{$n=$this->createMock(Folder::class);$n->method('getId')->willReturn($state->parentId);$n->method('getPath')->willReturn('/bob/files');$n->method('isReadable')->willReturn($state->readable);$n->method('isCreatable')->willReturn($state->creatable);$u=$this->createMock(\OCP\IUser::class);$u->method('getUID')->willReturn('bob');$n->method('getOwner')->willReturn($u);$storage=$this->createMock(\OCP\Files\Storage\IStorage::class);$storage->method('getId')->willReturn('home::bob');$n->method('getStorage')->willReturn($storage);return$n;};
  $read=function(string $path)use($state,$folder):\OCP\Files\Node {
   $state->reads++;$state->events[]='read';
   if($path==='/bob/files')return$folder();
   $n=$this->createMock(File::class);$id=$path==='/alice/files/a'?1:2;$n->method('getId')->willReturn($id);$n->method('getPath')->willReturn($path);$n->method('getEtag')->willReturn($state->etag);$n->method('getSize')->willReturn(8);$u=$this->createMock(\OCP\IUser::class);$u->method('getUID')->willReturn($id===2?$state->owner:$state->sourceOwner);$n->method('getOwner')->willReturn($u);$n->method('isReadable')->willReturn($state->readable);$n->method('getParent')->willReturnCallback($folder);$storage=$this->createMock(\OCP\Files\Storage\IStorage::class);$storage->method('getId')->willReturn($id===2?'home::bob':'home::alice');$n->method('getStorage')->willReturn($storage);$n->method('fopen')->willReturnCallback(static function()use($state){if($state->readMutation==='scope')$state->revoke=true;if($state->readMutation==='etag')$state->etag='changed!';$s=fopen('php://temp','w+b');fwrite($s,$state->bytes);rewind($s);return$s;});return$n;
  };
  $target=static function()use($state):void{$state->events[]='target';if($state->targetMutates!==null)$state->{$state->targetMutates}=false;if($state->targetRevoked)throw new RuntimeException('target');};
  $authorize=static function(array$manifest,array$copies,array$parents,?Closure$resolve=null)use($state):void{$state->events[]='authorize';if($state->revoke)throw new RuntimeException('scope');if($state->authMutates!==null)$state->{$state->authMutates}=false;if($resolve!==null)$resolve();$state->authorized++;};
  $factory=static fn(?array$other=null,?Closure$authorization=null)=>new TransferFinalFiles($other??$records,[], $parents,'bob',$read,static fn(int$id):array=>$cache,static function($node):void{},$target,$authorization??$authorize);
  return(object)compact('state','factory','records','parents');
 }
 public function testEveryUseReconstructsNodesBeforeRefreshingTargetAndResourceAuthority():void {
  $f=$this->fixture();$v=($f->factory)();$v->assert();$v->assert();self::assertSame(56,$f->state->reads);self::assertSame(4,$f->state->authorized);self::assertSame('target',$f->state->events[0]);
 }
 public function testChangedFreshMetadataOrBytesCannotUsePriorSuccessfulValidation():void {
  foreach(['etag','owner','sourceOwner','bytes']as$field){$f=$this->fixture();$v=($f->factory)();$v->assert();$f->state->$field='changed!';$error=null;try{$v->assert();}catch(RuntimeException$e){$error=$e->getMessage();}self::assertSame($field==='bytes'?'Transfer final bytes changed':'Transfer final file changed',$error,$field);self::assertSame($field==='bytes'?3:2,$f->state->authorized);}
 }
 public function testRevocationsAfterMetadataReadsCannotReturnSuccessfulFinalization():void {
  foreach(['revoke','targetRevoked']as$field){$f=$this->fixture();$v=($f->factory)();$v->assert();$f->state->$field=true;$error=null;try{$v->assert();}catch(RuntimeException$e){$error=$e->getMessage();}self::assertSame($field==='revoke'?'scope':'target',$error);self::assertSame(2,$f->state->authorized);}
 }
 public function testStorageWaitCannotRetainNodePermissionsOrPriorAuthority():void {
  foreach(['scope','etag']as$mutation){$f=$this->fixture();$f->state->readMutation=$mutation;$v=($f->factory)();$error=null;try{$v->assert();}catch(RuntimeException$e){$error=$e->getMessage();}self::assertSame($mutation==='scope'?'scope':'Transfer final file changed',$error);self::assertSame(1,$f->state->authorized);}
 }
 public function testNativePermissionSnapshotsAreReconstructedAfterTargetAndPolicyWaits():void {
  foreach(['targetMutates','authMutates']as$wait)foreach(['readable','creatable']as$permission){$f=$this->fixture();$v=($f->factory)();$v->assert();$f->state->$wait=$permission;$error=null;try{$v->assert();}catch(RuntimeException$e){$error=$e->getMessage();}self::assertSame($permission==='readable'?'Transfer final native file access changed':'Transfer final native folder access changed',$error,$wait.':'.$permission);}
 }
 public function testActualNativeParentSubstitutionCannotUseSameCopyPath():void {
  $f=$this->fixture();$v=($f->factory)();$v->assert();$f->state->parentId=77;$error=null;try{$v->assert();}catch(RuntimeException$e){$error=$e->getMessage();}self::assertSame('Transfer final actual parent changed',$error);
 }
 public function testProjectionAllows1000DistinctIdentitiesButRefuses1001AndOversizedState():void {
  $f=$this->fixture();$rows=[];for($i=1;$i<=1000;$i++){$r=$f->records[0];$r['source']['id']=$i;$r['copy']['id']=10000+$i;$r['source']['path'].=$i;$r['copy']['path'].=$i;$rows[]=$r;}self::assertInstanceOf(TransferFinalFiles::class,($f->factory)($rows));
  $r=$rows[999];$r['source']['id']=1001;$r['copy']['id']=11001;$rows[]=$r;$error=false;try{($f->factory)($rows);}catch(RuntimeException){$error=true;}self::assertTrue($error);
  $rows=$f->records;$rows[0]['source']['path']=str_repeat('x',1048576);$error=false;try{($f->factory)($rows);}catch(RuntimeException){$error=true;}self::assertTrue($error);self::assertSame(0,$f->state->reads);
 }
 public function testProjectionRefusesDuplicateOrMissingParentBeforeReading():void {
  $f=$this->fixture();$bad=$f->records;$bad[0]['parent_id']=4;$error=false;try{($f->factory)($bad);}catch(RuntimeException){$error=true;}self::assertTrue($error);self::assertSame(0,$f->state->reads);
  $bad=$f->records;$bad[0]['copy']['path']='/bob/other/a';$error=false;try{($f->factory)($bad);}catch(RuntimeException){$error=true;}self::assertTrue($error);self::assertSame(0,$f->state->reads);
  $bad=[$f->records[0],$f->records[0]];$error=false;try{($f->factory)($bad);}catch(RuntimeException){$error=true;}self::assertTrue($error);self::assertSame(0,$f->state->reads);
 }
 public function testResolverMustBeUsedExactlyOnceBeforeAuthorizationReturns():void {
  $f=$this->fixture();foreach(['missing','double']as$which){$authorize=static function(array$m,array$c,array$p,Closure$resolve)use($which):void{if($which==='double'){$resolve();$resolve();}};$v=($f->factory)(null,$authorize);$error=null;try{$v->assert();}catch(LogicException$e){$error=$e->getMessage();}self::assertSame($which==='missing'?'Transfer final resolution was not synchronous':'Transfer final resolution repeated',$error);}
 }

}
