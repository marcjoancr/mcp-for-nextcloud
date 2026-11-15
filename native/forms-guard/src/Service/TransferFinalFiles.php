<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\Files\File;
use OCP\Files\Folder;
/** Immutable bounded identities only; every use obtains fresh nodes and fresh authority. */
final class TransferFinalFiles {
 public function __construct(private array $records,private array $sources,private array $parents,private string $targetUid,
  private \Closure $read,private \Closure $cache,private \Closure $admit,private \Closure $target,private \Closure $authorize){
  if(!array_is_list($records)||!array_is_list($sources)||!array_is_list($parents)||count($records)>1000||count($sources)>1000||count($parents)>2000
   ||strlen(json_encode([$records,$sources,$parents],JSON_THROW_ON_ERROR))>1048576||$targetUid==='')throw new \RuntimeException('Transfer final projection is invalid');
  $ids=[];$parentIds=[];
  foreach($parents as$p){$this->folderRecord($p);if(isset($parentIds[$p['id']])||$p['owner']!==$targetUid)throw new \RuntimeException('Transfer final parent identity is invalid');$parentIds[$p['id']]=$p;}
  foreach($sources as$p)$this->folderRecord($p);
  foreach($records as$r){foreach(['source','copy']as$kind){$v=$r[$kind]??null;if(!is_array($v)||!is_int($v['id']??null)||$v['id']<1||!is_string($v['path']??null)||!is_string($v['owner']??null)||$v['owner']===''||!is_string($v['storage']??null)||$v['storage']===''||!is_string($v['etag']??null)||!is_int($v['size']??null)||$v['size']<0
    ||!is_string($v['cache']??null)||preg_match('/^[a-f0-9]{64}$/D',$v['cache'])!==1||isset($ids[$v['id']]))throw new \RuntimeException('Transfer final file identity is invalid');$ids[$v['id']]=true;}
   if(!is_string($r['source']['sha256']??null)||preg_match('/^[a-f0-9]{64}$/D',$r['source']['sha256'])!==1||$r['source']['size']!==$r['copy']['size']||$r['copy']['owner']!==$targetUid||!isset($parentIds[$r['parent_id']??0])||dirname($r['copy']['path'])!==$parentIds[$r['parent_id']]['path'])throw new \RuntimeException('Transfer final copy binding is invalid');
  }
 }
 private function folderRecord(array$p):void {
  if(!is_int($p['id']??null)||$p['id']<1||!is_string($p['path']??null)||!is_string($p['owner']??null)||$p['owner']===''||!is_string($p['storage']??null)||$p['storage']==='')throw new \RuntimeException('Transfer final folder identity is invalid');
 }
 private function identity(array$p,bool$file): \OCP\Files\Node {
  BulkDeadline::check();$n=($this->read)($p['path']);
  if(($file?!$n instanceof File:!$n instanceof Folder)||(int)$n->getId()!==$p['id']||$n->getPath()!==$p['path']||$n->getOwner()?->getUID()!==$p['owner']||$n->getStorage()->getId()!==$p['storage']
   ||($file&&($n->getEtag()!==$p['etag']||(int)$n->getSize()!==$p['size'])))throw new \RuntimeException($file?'Transfer final file changed':'Transfer final folder changed');return$n;
 }
 private function nodes(bool$nativeAcl=false):array {
  // Complete SQL/storage/parent checks before constructing the nodes used by final ACL checks.
  foreach($this->records as$r){foreach(['source','copy']as$kind){$p=$r[$kind];if(!hash_equals($p['cache'],hash('sha256',json_encode(($this->cache)($p['id']),JSON_THROW_ON_ERROR))))throw new \RuntimeException('Transfer final file changed');($this->admit)($this->identity($p,true));}
   $copy=$this->identity($r['copy'],true);$actual=$copy->getParent();$p=$this->parentRecord($r['parent_id']);if((int)$actual->getId()!==$p['id']||$actual->getPath()!==$p['path']||$actual->getOwner()?->getUID()!==$p['owner']||$actual->getStorage()->getId()!==$p['storage'])throw new \RuntimeException('Transfer final actual parent changed');
  }
  foreach([...$this->sources,...$this->parents]as$p)($this->admit)($this->identity($p,false));
  $manifest=['files'=>[]];$copies=[];$streams=[];$parents=[];
  foreach($this->records as$r){$source=$this->identity($r['source'],true);$copy=$this->identity($r['copy'],true);if($nativeAcl&&(!$source->isReadable()||!$copy->isReadable()))throw new \RuntimeException('Transfer final native file access changed');$manifest['files'][]=['file_id'=>$r['source']['id']];$copies[]=$copy;$streams[]=[$source,$copy,$r['source']];}
  foreach($this->sources as$p){$n=$this->identity($p,false);if($nativeAcl&&!$n->isReadable())throw new \RuntimeException('Transfer final native folder access changed');$manifest['files'][]=['file_id'=>$p['id']];}
  foreach($this->parents as$p){$n=$this->identity($p,false);if($nativeAcl&&(!$n->isReadable()||!$n->isCreatable()))throw new \RuntimeException('Transfer final native folder access changed');$parents[]=$n;}
  return[$manifest,$copies,$parents,$streams];
 }
 private function parentRecord(int$id):array {foreach($this->parents as$p)if($p['id']===$id)return$p;throw new \RuntimeException('Transfer final parent unavailable');}
 private function admitted(bool$final):array {
  ($this->target)($final);[$manifest,$copies,$parents]=$this->nodes();$resolved=false;$projection=null;
  ($this->authorize)($manifest,$copies,$parents,function()use(&$resolved,&$projection):array{if($resolved)throw new \LogicException('Transfer final resolution repeated');$resolved=true;return$projection=$this->nodes(true);});
  if(!$resolved||!is_array($projection))throw new \LogicException('Transfer final resolution was not synchronous');return$projection;
 }
 private function bytes(File$file,array$meta):void {
  $s=$file->fopen('rb');if(!is_resource($s))throw new \RuntimeException('Transfer final bytes unavailable');$ctx=hash_init('sha256');$count=0;
  try{while(!feof($s)){BulkDeadline::check();$chunk=fread($s,65536);if($chunk===false)throw new \RuntimeException('Transfer final read failed');$count+=strlen($chunk);if($count>$meta['size'])throw new \RuntimeException('Transfer final bytes changed');hash_update($ctx,$chunk);}}finally{fclose($s);}
  if($count!==$meta['size']||!hash_equals($meta['sha256'],hash_final($ctx)))throw new \RuntimeException('Transfer final bytes changed');
 }
 public function assert(bool $final=false):void {
  [, , ,$streams]=$this->admitted($final);
  foreach($streams as[$source,$copy,$meta]){$this->bytes($source,$meta);$this->bytes($copy,$meta);}
  unset($streams);$this->admitted($final);BulkDeadline::check();
 }
}
