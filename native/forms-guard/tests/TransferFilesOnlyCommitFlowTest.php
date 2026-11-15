<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Lock\ILockingProvider;
use OCA\Forms\Mcp\Service\TransferFinalFiles;
final class TransferServerHarness {public static object $state;public static function get(string$class):object {return match($class){'FormsRevisionFence'=>self::$state->fence,IRootFolder::class=>self::$state->root,'OCA\\Mcp\\Mcp\\Operations\\ActorIdentity'=>new class{public function assertCurrent(string$uid,mixed$actor):void{if($uid!=='bob'||$actor!=='captured')throw new LogicException('target actor');}},'OCA\\Forms\\Mcp\\Comments\\CommentsOutbox','ManagedSharePhase','FormsEffectOutbox'=>new class{public function drain():void{}},default=>throw new LogicException('Unexpected service '.$class)};}}
final class TransferOriginHarness {
 public static function active():bool{return true;}public static function assertFresh():void{}public static function assertFiles(string$a,array$m):void{}
 public static function transferFilesAuthority():Closure {return static function(array$m,array$c,array$p,Closure$resolve):void {TransferServerHarness::$state->admissions[]=array_column($m['files'],'file_id');$resolve();};}
}
final class TransferDestinationHarness {public static function authorize($node,bool$create):void{}}
final class TransferStorageHarness {public static function node($node):void{}}
final class TransferFreshCoreHarness {public function __construct(string$uid){if($uid!=='bob')throw new LogicException('target uid');}public function assertPrincipal(bool$a,bool$b):void{}}
final class TransferCommentsHarness {public static function principal(string$uid):string{return 'target-principal';}}
final class TransferFlowStore {
 public function __construct(public array$rows,public array$meta){}
 public function page(string$id,string$kind,int$cursor):array {if($kind!=='files')return[];return array_slice(array_values(array_filter($this->rows,static fn($r)=>$r['id']>$cursor)),0,100);}
 public function mapped(string$id,string$kind,int$source):int{return 10000+$source;}
 public function one(string$id,string$kind,int$source):array{return$this->meta[$source];}
}
final class TransferFlowDatabase {
 public string$owner='alice';public array$cache=[];public bool$transaction=false;private string$before='alice';public array$events=[];
 public function getDatabaseProvider(bool$fresh=true):string{return 'postgres';}public function inTransaction():bool{return$this->transaction;}
 public function beginTransaction():void{$this->before=$this->owner;$this->transaction=true;$this->events[]='begin';}
 public function executeStatement(string$sql):int{return 1;}
 public function commit():void{$this->events[]='commit';$this->transaction=false;}
 public function rollBack():void{$this->owner=$this->before;$this->events[]='rollback';$this->transaction=false;}

 public function executeQuery(string$sql,array$args):object {
  if(str_contains($sql,'filecache'))$data=$this->cache[$args[0]]??null;
  elseif(str_contains($sql,'users'))$data=['uid'=>'bob'];
  elseif(str_contains($sql,'forms_v2_forms'))$data=['owner_id'=>$this->owner,'state'=>0];
  else throw new LogicException('Unexpected database query');
  return new class($data){public function __construct(private mixed$data){}public function fetch():mixed{return$this->data;}public function fetchOne():mixed{return is_array($this->data)?reset($this->data):$this->data;}};
 }
}
final class TransferFilesOnlyCommitFlowTest extends TestCase {
 protected function setUp():void {require_once __DIR__.'/../src/Service/BulkDeadline.php';require_once __DIR__.'/../src/Service/TransferFinalFiles.php';require_once __DIR__.'/../src/BeforeEffect.php';require_once __DIR__.'/../src/Service/HistoricalFiles.php';if(!class_exists('BulkDeadline',false))class_alias(\OCA\Forms\Mcp\Service\BulkDeadline::class,'BulkDeadline');}
 private function fixture(int$count):object {
  $state=(object)['readable'=>true,'admissions'=>[],'locked'=>[],'released'=>[],'outcomes'=>[],'homeLookups'=>[]];
  $db=new TransferFlowDatabase();$state->fence=$this->fence($db);$rows=[];$meta=[];$paths=[];
  $storage=[];foreach(['alice','bob']as$uid){$s=$this->createMock(\OCP\Files\Storage\IStorage::class);$s->method('getId')->willReturn('home::'.$uid);$storage[$uid]=$s;}
  $user=[];foreach(['alice','bob']as$uid){$u=$this->createMock(\OCP\IUser::class);$u->method('getUID')->willReturn($uid);$user[$uid]=$u;}
  $parent=function()use($state,$storage,$user):Folder{$p=$this->createMock(Folder::class);$p->method('getId')->willReturn(9999);$p->method('getPath')->willReturn('/bob/files');$p->method('getOwner')->willReturn($user['bob']);$p->method('getStorage')->willReturn($storage['bob']);$p->method('isReadable')->willReturn($state->readable);$p->method('isCreatable')->willReturn($state->readable);$p->method('lock')->willReturnCallback(static function()use($state):void{$state->locked[]=9999;});$p->method('unlock')->willReturnCallback(static function()use($state):void{$state->released[]=9999;});return$p;};
  $file=function(int$id)use($state,$storage,$user,$parent):File{$uid=$id>10000?'bob':'alice';$n=$id>10000?$id-10000:$id;$f=$this->createMock(File::class);$f->method('getId')->willReturn($id);$f->method('getPath')->willReturn('/'.$uid.'/files/f'.$n);$f->method('getOwner')->willReturn($user[$uid]);$f->method('getStorage')->willReturn($storage[$uid]);$f->method('getEtag')->willReturn('sealed');$f->method('getSize')->willReturn(8);$f->method('isReadable')->willReturn($state->readable);$f->method('getParent')->willReturnCallback($parent);$f->method('lock')->willReturnCallback(static function()use($state,$id):void{$state->locked[]=$id;});$f->method('unlock')->willReturnCallback(static function()use($state,$id):void{$state->released[]=$id;});$f->method('fopen')->willReturnCallback(static function(){$s=fopen('php://temp','w+b');fwrite($s,'original');rewind($s);return$s;});return$f;};
  for($i=1;$i<=$count;$i++){$rows[]=['id'=>$i,'data'=>['file_id'=>$i,'delete'=>false,'path'=>'/alice/files/f'.$i,'etag'=>'sealed','size'=>8,'sha256'=>hash('sha256','original')]];foreach([$i,10000+$i]as$id)$db->cache[$id]=['storage'=>$id>10000?2:1,'path'=>'files/f'.$i,'size'=>8,'etag'=>'sealed'];$meta[$i]=['file_id'=>10000+$i,'path'=>'/bob/files/f'.$i,'etag'=>'sealed','size'=>8,'sha256'=>hash('sha256','original'),'cache'=>$db->cache[10000+$i]];}
  $homes=[];foreach(['alice','bob']as$uid){$h=$this->createMock(Folder::class);$h->method('getById')->willReturnCallback(static fn(int$id)=>[$file($id)]);$homes[$uid]=$h;}
  $root=$this->createMock(IRootFolder::class);$root->method('getUserFolder')->willReturnCallback(static function(string$uid)use($homes,$state){$state->homeLookups[]=$uid;return$homes[$uid];});$root->method('get')->willReturnCallback(static function(string$path)use($file,$parent){if($path==='/bob/files')return$parent();if(preg_match('~^/(alice|bob)/files/f([1-9][0-9]*)$~D',$path,$m)!==1)throw new LogicException('Unexpected path');return$file((int)$m[2]+($m[1]==='bob'?10000:0));});$state->root=$root;TransferServerHarness::$state=$state;
  $p=__DIR__.'/../src/Service/BulkFiles.php';$tree=(new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse(file_get_contents($p));$methods=[];foreach((new \PhpParser\NodeFinder())->findInstanceOf($tree,\PhpParser\Node\Stmt\ClassMethod::class)as$m)if(in_array($m->name->toString(),['commitTransfer','commitWithoutManagedShares','commitWithManagedShares','source','manifest'],true))$methods[]=$m;
  $code=(new \PhpParser\PrettyPrinter\Standard())->prettyPrint($methods);$code=str_replace(['Server::','OriginContext::','StorageAdmission::','DestinationFiles::','new FreshCore(','\\OCA\\Forms\\Mcp\\Comments\\CommentsRuntime::'],['TransferServerHarness::','TransferOriginHarness::','TransferStorageHarness::','TransferDestinationHarness::','new TransferFreshCoreHarness(','TransferCommentsHarness::'],$code);
  static$serial=0;$name='TransferBulkFlow'.(++$serial);eval('use OCP\\Files\\IRootFolder;use OCP\\Files\\Folder;use OCP\\Files\\File;use OCP\\Lock\\ILockingProvider;use OCA\\Forms\\Mcp\\Service\\TransferFinalFiles;use OCA\\Forms\\Mcp\\Service\\HistoricalFiles;class '.$name.' {public function __construct(public object $db,public object $store){}public function fingerprint(int $id):string{return "sealed-fingerprint";}'.$code.'}');
  $bulk=new $name($db,new TransferFlowStore($rows,$meta));$j=['id'=>'job','action'=>'forms_transfer','uid'=>'alice','form'=>['id'=>4,'owner_id'=>'alice'],'input'=>['owner_id'=>'bob'],'target_actor'=>'captured','target_principal'=>'target-principal','counts'=>['files'=>$count,'managed_shares'=>0],'file_revision'=>'sealed-fingerprint'];return(object)compact('bulk','j','db','state');
 }
 private function fence(object$db):object {
  $p=__DIR__.'/../src/Service/FormsRevisionFence.php';$tree=(new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse(file_get_contents($p));$wanted=['active','afterTransaction','beforeCommit','transact'];$methods=[];foreach((new \PhpParser\NodeFinder())->findInstanceOf($tree,\PhpParser\Node\Stmt\ClassMethod::class)as$m)if(in_array($m->name->toString(),$wanted,true)){$m->flags=\PhpParser\Modifiers::PUBLIC;$methods[]=$m;}
  $code=(new \PhpParser\PrettyPrinter\Standard())->prettyPrint($methods);$code=str_replace(['OriginContext::','Server::'],['TransferOriginHarness::','TransferServerHarness::'],$code);static$serial=0;$name='TransferFenceFlow'.(++$serial);eval('class '.$name.' {public function __construct(public object $db){}private int $depth=0;private array $transactionCallbacks=[];private array $resultSourcesTouched=[];private array $linkedSourcesTouched=[];private ?Closure $beforeCommitCheck=null;private bool $checkingBeforeCommit=false;public function assertSession():void{}'.$code.'}');return new $name($db);
 }
 public function test101FilesReachFinalCheckAfterOwnerAndReceiptWorkWhileAllLocksRemainHeld():void {
  $f=$this->fixture(101);$result=$f->state->fence->transact(function()use($f):int{$f->state->fence->afterTransaction(static function(bool$committed)use($f):void{$f->state->outcomes[]=$committed;});return$f->bulk->commitTransfer($f->j,microtime(true),function()use($f):int{self::assertSame([],$f->state->released);$f->db->owner='bob';$f->db->events[]='owner and receipt';return 1;});});
  self::assertSame(1,$result);self::assertSame('bob',$f->db->owner);self::assertSame([true],$f->state->outcomes);self::assertCount(4,$f->state->admissions);foreach($f->state->admissions as$page)self::assertSame(range(1,101),$page);self::assertSame(array_reverse($f->state->locked),$f->state->released);self::assertSame(['begin','owner and receipt','commit'],$f->db->events);
 }
 public function testLateAclWithdrawalRollsBackOwnerAndReleasesEveryLockAfterReceiptWork():void {
  $f=$this->fixture(3);$error=null;try{$f->state->fence->transact(function()use($f):int{$f->state->fence->afterTransaction(static function(bool$committed)use($f):void{$f->state->outcomes[]=$committed;});$result=$f->bulk->commitTransfer($f->j,microtime(true),function()use($f):int{$f->db->owner='bob';return 1;});$f->db->events[]='late receipt wait';$f->state->readable=false;return$result;});}catch(RuntimeException$e){$error=$e->getMessage();}
  self::assertSame('Transfer final native file access changed',$error);self::assertSame('alice',$f->db->owner);self::assertSame([false],$f->state->outcomes);self::assertSame(['begin','late receipt wait','rollback'],$f->db->events);self::assertSame(array_reverse($f->state->locked),$f->state->released);
 }
 public function testFileFreeTransferDoesNotMaterializeEitherHomeOrFileResources():void {
  $f=$this->fixture(0);$result=$f->state->fence->transact(fn()=>$f->bulk->commitTransfer($f->j,microtime(true),function()use($f):int{$f->db->owner='bob';return 1;}));self::assertSame(1,$result);self::assertSame([],$f->state->homeLookups);self::assertSame([],$f->state->locked);self::assertSame([[],[],[],[]],$f->state->admissions);
 }
 public function testManagedShareDispatchKeepsTheExistingSourcePathUntilItsSeparateReplacement():void {
  $f=$this->fixture(1);$f->j['counts']['managed_shares']=1;$result=$f->state->fence->transact(fn()=>$f->bulk->commitTransfer($f->j,microtime(true),function()use($f):int{$f->db->owner='bob';return 1;}));self::assertSame(1,$result);self::assertSame([],$f->state->admissions);self::assertSame(array_reverse($f->state->locked),$f->state->released);
 }
 public function testMalformedManagedShareCountsRefuseBeforeLocksOrEffects():void {
  foreach([null,-1,'0']as$count){$f=$this->fixture(1);$f->j['counts']['managed_shares']=$count;$error=null;try{$f->bulk->commitTransfer($f->j,microtime(true),static fn()=>1);}catch(RuntimeException$e){$error=$e->getMessage();}self::assertSame('Transfer managed share count is invalid',$error);self::assertSame([],$f->state->locked);self::assertSame([],$f->db->events);}
 }

}
