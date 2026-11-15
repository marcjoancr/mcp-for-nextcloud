<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\{FinalReadSet,FinalReadFiles,FinalNativeAcl};
spl_autoload_register(static function(string$class):void{
 $base=dirname(__DIR__);$file=null;
 if(str_starts_with($class,'OCP\\'))$file=$base.'/references/lib__public__'.str_replace('\\','__',substr($class,4)).'.php';
 elseif(str_starts_with($class,'OC\\'))$file=$base.'/references/lib__private__'.str_replace('\\','__',substr($class,3)).'.php';
 elseif(str_starts_with($class,'OCA\\Forms\\Mcp\\Service\\'))$file=$base.'/src/'.substr($class,strrpos($class,'\\')+1).'.php';
 if($file!==null&&is_file($file))require_once$file;
});
eval('namespace OC\\Core\\AppInfo; final class Application {const APP_ID="core";}');
eval('namespace OCP; final class Server {public static function get(string $id):never {throw new \\LogicException("Global lookup trap");}}');
require_once dirname(__DIR__).'/references/core__Listener__RestrictInteractionListener.php';
final class NativeFinalSnapshotTest extends TestCase {
 private function subject():\OCP\IUser{$u=$this->createMock(\OCP\IUser::class);$u->method('getUID')->willReturn('synthetic');return$u;}
 private function handler(bool$enabled,bool$onlyMembers=false):\OC\Core\Listener\RestrictInteractionListener{
  $m=$this->createMock(\OCP\Share\IManager::class);$m->method('shareApiEnabled')->willReturn($enabled);$m->method('allowGroupSharing')->willReturn(true);$m->method('shareWithGroupMembersOnly')->willReturn($onlyMembers);$m->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturn([]);
  $g=$this->createMock(\OCP\IGroupManager::class);$l=$this->createMock(\OCP\IL10N::class);$l->method('t')->willReturnArgument(0);$f=$this->createMock(\OCP\L10N\IFactory::class);$f->method('get')->willReturn($l);
  return new \OC\Core\Listener\RestrictInteractionListener($m,$g,$f);
 }
 private function set():FinalReadSet{return new FinalReadSet(static function():void{});}
 private function denied(Closure$run):void{$message=null;try{$run();}catch(RuntimeException$e){$message=$e->getMessage();}self::assertSame('Final read dependency changed',$message);}
 public function testCoreConfigRevokedInsideDuplicateSelectCannotSurviveTheLastSnapshot():void{
  $set=$this->set();$config=['configkey'=>'shareapi_enabled','configvalue'=>'yes'];$set->add('SELECT configkey,configvalue FROM *PREFIX*appconfig WHERE appid = ?',['core'],[],[$config]);
  $core=$this->handler(true);
  // Controlled SQL-boundary callback: the captured native Core manager still contains yes.
  $duplicateSelect=static function()use(&$config):array{$config['configvalue']='no';return[];};$duplicateSelect();
  $query=new \OCP\Interaction\RestrictInteractionEvent('synthetic',$this->subject(),[],new \OCP\Interaction\Actions\ShareAction(1),[new \OCP\Interaction\Receivers\UserReceiver('recipient',$this->subject())]);
  $core->handle($query); // R0 approves using its old DTO; the new final read must refuse.
  $set->seal();$this->denied(fn()=>$set->verify([['q'=>0,'row'=>json_encode($config,JSON_THROW_ON_ERROR)]]));
 }
 public function testNativeGroupAllowanceRevokedInsideRenewViewsCannotSurviveTheLastSnapshot():void{
  $set=$this->set();$members=[['uid'=>'synthetic']];$set->add('SELECT uid FROM *PREFIX*group_user WHERE gid = ? AND uid = ?',['group','synthetic'],[],$members);
  $group=$this->createMock(\OCP\IGroup::class);$group->method('inGroup')->willReturn(true);$group->method('getGID')->willReturn('group');
  $query=new \OCP\Interaction\RestrictInteractionEvent('synthetic',$this->subject(),[],new \OCP\Interaction\Actions\ShareAction(1),[new \OCP\Interaction\Receivers\GroupReceiver('group',$group)]);
  $this->handler(true,true)->handle($query);
  $renewViews=static function()use(&$members):void{$members=[];};$renewViews();$set->seal();$this->denied(fn()=>$set->verify([]));
 }
 private function folder(int$permissions):\OC\Files\Node\Folder{
  $info=(new ReflectionClass(\OC\Files\FileInfo::class))->newInstanceWithoutConstructor();(new ReflectionProperty($info,'data'))->setValue($info,new \OC\Files\Cache\CacheEntry(['permissions'=>$permissions]));
  $node=(new ReflectionClass(\OC\Files\Node\Folder::class))->newInstanceWithoutConstructor();(new ReflectionProperty(\OC\Files\Node\Node::class,'fileInfo'))->setValue($node,$info);return$node;
 }
 public function testNativeFileInfoReadAndCreateSnapshotsAreRejectedWhenRenewMutatesTheirRows():void{
  foreach([4,1]as$revoked){
   $node=$this->folder(5);$row=['fileid'=>7,'permissions'=>5];$set=$this->set();$set->add('SELECT fileid,permissions FROM *PREFIX*filecache WHERE fileid = ?',[7],[],[$row]);FinalReadFiles::samePermissions($node,5);
   $renewViews=static function()use(&$row,$revoked):void{$row['permissions']=$revoked;};$renewViews();
   FinalNativeAcl::readable($node,true); // Real pinned Node/FileInfo getters still return the old snapshot.
   $set->seal();$this->denied(fn()=>$set->verify([['q'=>0,'row'=>json_encode($row,JSON_THROW_ON_ERROR)]]));
  }
 }
 public function testUnchangedNativeAclAndCoreInputsPassTheSnapshot():void{
  $node=$this->folder(5);$set=$this->set();$set->add('SELECT permissions FROM *PREFIX*filecache WHERE fileid = ?',[7],[],[['permissions'=>5]]);FinalReadFiles::samePermissions($node,5);$set->seal();$set->verify([['q'=>0,'row'=>'{"permissions":5}']]);FinalNativeAcl::readable($node,true);self::assertTrue(true);
 }
 public function testMismatchingNativeSnapshotCannotBeRegisteredAsIfFresh():void{
  $this->expectException(RuntimeException::class);$this->expectExceptionMessage('Final file ACL snapshot differs');FinalReadFiles::samePermissions($this->folder(5),1);
 }
}
