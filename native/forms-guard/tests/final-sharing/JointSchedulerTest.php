<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use OCP\Files\{File,Folder};
use OCA\Forms\Mcp\Service\TransferFinalFiles;
// Synthetic collaborator test of the real joint scheduler body. No Core graph or sharing collector runs.
eval('namespace OCA\\Forms\\Mcp\\Service; final class FinalTransferShareCheck {
 public bool $closed=false; public ?bool $permissions=null; private FinalReadSet $set;private array $kinds=[];
 public function __construct(public object $state,public ?string $mutation=null){$this->set=new FinalReadSet(static function():void{});}
 private function input(string $kind,array $row):void {$this->set->add("SELECT value FROM *PREFIX*appconfig WHERE appid = ?",[$kind],[],[$row]);if(!in_array($kind,$this->kinds,true))$this->kinds[]=$kind;}
 public function collect(FinalShareBudget $budget):array {$this->state->events[]="share.read";$this->input("coreEnabled",["value"=>$this->state->coreEnabled]);$this->input("member",["value"=>$this->state->member]);if($this->mutation==="scope")$this->state->revoke=true;if($this->mutation==="acl")$this->state->readable=false;return [];}
 public function captureFile(\\OCP\\Files\\Node $node,FinalShareBudget $budget):void {$this->input("readable",["value"=>$node->isReadable()]);if($node instanceof \\OCP\\Files\\Folder)$this->input("creatable",["value"=>$node->isCreatable()]);}
 public function renewViews(FinalShareBudget $budget):void {$this->state->events[]="share.views";foreach(["late-read"=>"readable","late-create"=>"creatable","late-config"=>"coreEnabled","late-member"=>"member"]as$case=>$field)if($this->mutation===$case)$this->state->$field=false;$this->permissions=$this->state->sharingReadable;$this->input("sharingReadable",["value"=>$this->permissions]);}
 public function admitSnapshot():void {$this->state->events[]="metadata.admit";$this->set->seal();}
 public function verifySnapshot():void {$this->state->events[]="snapshot.read";$rows=[];foreach($this->kinds as$i=>$key)$rows[]=["q"=>$i,"row"=>json_encode(["value"=>$this->state->$key])];$this->set->verify($rows);}
 public function resourceProjection():array{return [[],[]];}
 public function resolve():void {$this->state->events[]="share.resolve";if($this->permissions!==true)throw new \\RuntimeException("sharing ACL revoked");}
 public function close():void{$this->closed=true;}
}');
eval('namespace OCA\\Forms\\Mcp\\Service; final class FinalNativeAcl {
 public static function initialized(\\OCP\\Files\\Node $node):void{}
 public static function readable(\\OCP\\Files\\Node $node,bool $create=false):void {if(!$node->isReadable()||($create&&!$node->isCreatable()))throw new \\RuntimeException("Transfer final native access changed");}
}');
require (require __DIR__.'/repository-root.php').'/native/forms-guard/src/Service/BulkDeadline.php';
require dirname(__DIR__).'/src/FinalShareBudget.php';
require dirname(__DIR__).'/src/FinalReadSet.php';
require dirname(__DIR__).'/adapters/TransferFinalFiles.php';
final class JointSchedulerTest extends TestCase {
 private function fixture():object {
  $state=(object)['etag'=>'sealed','owner'=>'bob','bytes'=>'original','reads'=>0,'revoke'=>false,'authorized'=>0,'targetRevoked'=>false,'events'=>[],'readMutation'=>null,'sourceOwner'=>'alice','readable'=>true,'sharingReadable'=>true,'creatable'=>true,'targetMutates'=>null,'authMutates'=>null,'parentId'=>3,'coreEnabled'=>true,'member'=>true];$cache=['storage'=>1,'path'=>'cache','size'=>8,'etag'=>'sealed'];$hash=hash('sha256',json_encode($cache,JSON_THROW_ON_ERROR));
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

 private function joint(object $f,?string $behavior=null):Closure {
  return static function(Closure $collect,Closure $resolve)use($f,$behavior):void {
   $f->state->events[]='resource.enter';$collect();
   if($behavior==='double-collect')$collect();
   $f->state->events[]='files.scope';if($f->state->revoke)throw new RuntimeException('scope');
   if($behavior==='skip-resolver')return;
   if($behavior==='sharing-acl')$f->state->sharingReadable=false;
   $resolve();if($behavior==='double-resolver')$resolve();
  };
 }
 public function testAllMaterializationAndMetadataAdmissionPrecedeLatestMcpThenOneSnapshot():void {
  $f=$this->fixture();$s=new \OCA\Forms\Mcp\Service\FinalTransferShareCheck($f->state);($f->factory)()->assertManaged($s,$this->joint($f));
  $events=$f->state->events;
  self::assertLessThan(array_search('files.scope',$events,true),array_search('share.read',$events,true));
  self::assertLessThan(array_search('files.scope',$events,true),array_search('share.views',$events,true));
  self::assertLessThan(array_search('files.scope',$events,true),array_search('metadata.admit',$events,true));
  self::assertLessThan(array_search('snapshot.read',$events,true),array_search('files.scope',$events,true));
  self::assertLessThan(array_search('share.resolve',$events,true),array_search('share.views',$events,true));
  self::assertLessThan(array_search('share.resolve',$events,true),array_search('files.scope',$events,true));self::assertTrue($s->closed);
 }
 public function testFilesScopeRevocationDuringSharingWaitRefusesBeforeResolver():void {
  $f=$this->fixture();$s=new \OCA\Forms\Mcp\Service\FinalTransferShareCheck($f->state,'scope');$message=null;
  try{($f->factory)()->assertManaged($s,$this->joint($f));}catch(RuntimeException $e){$message=$e->getMessage();}
  self::assertSame('scope',$message);self::assertNotContains('share.resolve',$f->state->events);self::assertTrue($s->closed);
 }
 public function testFilesAclRevocationDuringSharingWaitIsSeenByRenewedFiles():void {
  $f=$this->fixture();$s=new \OCA\Forms\Mcp\Service\FinalTransferShareCheck($f->state,'acl');$message=null;
  try{($f->factory)()->assertManaged($s,$this->joint($f));}catch(RuntimeException $e){$message=$e->getMessage();}
  self::assertSame('Transfer final native access changed',$message);self::assertNotContains('share.resolve',$f->state->events);self::assertTrue($s->closed);
 }
 public function testSharingAclRevocationDuringMcpWarmIsSeenByFinalViewRenewal():void {
  $f=$this->fixture();$s=new \OCA\Forms\Mcp\Service\FinalTransferShareCheck($f->state);$message=null;
  try{($f->factory)()->assertManaged($s,$this->joint($f,'sharing-acl'));}catch(RuntimeException $e){$message=$e->getMessage();}
  self::assertSame('Final read dependency changed',$message);self::assertTrue($s->closed);
 }
 public function testFilesReadCreateAndCoreInputsRevokedInsideRenewRefuseBeforeEitherFamilyResolves():void {
  foreach(['late-read','late-create','late-config','late-member']as$case){
   $f=$this->fixture();$s=new \OCA\Forms\Mcp\Service\FinalTransferShareCheck($f->state,$case);$message=null;
   try{($f->factory)()->assertManaged($s,$this->joint($f));}catch(RuntimeException$e){$message=$e->getMessage();}
   self::assertSame('Final read dependency changed',$message,$case);self::assertNotContains('share.resolve',$f->state->events);self::assertTrue($s->closed);
  }
 }
 public function testSkippedAndRepeatedCallbacksRefuseAndClose():void {
  foreach(['skip-resolver'=>'Joint transfer authorization was not synchronous','double-collect'=>'Joint transfer collection repeated','double-resolver'=>'Joint transfer resolution escaped or repeated']as$case=>$expected){
   $f=$this->fixture();$s=new \OCA\Forms\Mcp\Service\FinalTransferShareCheck($f->state);$message=null;
   try{($f->factory)()->assertManaged($s,$this->joint($f,$case));}catch(LogicException $e){$message=$e->getMessage();}
   self::assertSame($expected,$message);self::assertTrue($s->closed);
  }
 }
}
