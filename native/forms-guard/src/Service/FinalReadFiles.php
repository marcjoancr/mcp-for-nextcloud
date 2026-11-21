<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Bind the exact native FileInfo ACL snapshot to recorded native cache rows before the last read. */
final class FinalReadFiles {
 public static function samePermissions(\OCP\Files\Node$node,int$recorded):void {
  FinalNativeAcl::initialized($node);
  if($node->getPermissions()!==$recorded)throw new \RuntimeException('Final file ACL snapshot differs');
 }
 public static function capture(\OCP\Files\Node$node,FinalShareBudget$budget,FinalReadConnection$db):void {
  FinalNativeAcl::initialized($node);
  if($node->getInternalPath()==='')throw new \RuntimeException('Final file root-storage permission profile is unqualified');
  $owner=$node->getOwner();if($owner===null)throw new \RuntimeException('Final file owner is unavailable');
  $subject=FinalReadCore::fresh($owner->getUID(),$db)->user();
  $root=FinalNativeAcl::root();
  $views=FinalShareViews::read($root,$subject,$node->getId(),$budget,static fn()=>BulkDeadline::check(),$db);
  $fresh=$views->node($node->getPath());
  if($fresh->getStorage()!==$node->getStorage()||$fresh->getInternalPath()!==$node->getInternalPath())throw new \RuntimeException('Final file mount identity changed');
  self::samePermissions($node,$fresh->getPermissions());
  $raw=static function(\OCP\Files\Node$n):array|\OCP\Files\Cache\ICacheEntry {
   $info=(new \ReflectionProperty(\OC\Files\Node\Node::class,'fileInfo'))->getValue($n);
   return(new \ReflectionProperty(\OC\Files\FileInfo::class,'data'))->getValue($info);
  };
  $old=$raw($node);$new=$raw($fresh);
  foreach(['fileid','storage','parent','size','permissions']as$key){
   $a=$old[$key]??null;$b=$new[$key]??null;
   $canonical=static fn(mixed$v):bool=>is_int($v)||(is_string($v)&&preg_match('/^-?(?:0|[1-9][0-9]*)$/D',$v)===1&&(string)(int)$v===$v);
   if(!$canonical($a)||!$canonical($b)||(int)$a!==(int)$b)throw new \RuntimeException('Final file metadata snapshot differs');
  }
  foreach(['path','mimetype','mimepart','etag']as$key)if(!is_string($old[$key]??null)||($old[$key]??null)!==($new[$key]??null))throw new \RuntimeException('Final file metadata snapshot differs');
 }
}
