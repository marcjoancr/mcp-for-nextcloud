<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\{Server,IDBConnection};
use OCP\Files\{File,Folder,IRootFolder,NotFoundException};
use OCP\Lock\ILockingProvider;
/** Each external effect has a durable no-replay claim and exact byte reservation before allocation. */
final class BulkFiles {
 public function __construct(private IDBConnection $db,private BulkStore $store){}
 private function references(int $form):array {
  return ['SELECT a.file_id FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id = ? AND a.file_id IS NOT NULL UNION SELECT file_id FROM *PREFIX*forms_v2_uploaded_files WHERE form_id = ? UNION SELECT file_id FROM *PREFIX*forms_mcp_artifacts WHERE form_id = ?',[$form,$form,$form]];
 }
 public function count(int $form):int {[$sql,$params]=$this->references($form);return (int)$this->db->executeQuery('SELECT COUNT(*) FROM ('.$sql.') bulk_files',$params)->fetchOne();}
 /** Bind approved IDs and native Files versions without loading file bodies or unbounded arrays. */
 public function fingerprint(int $form):string {
  [$sql,$params]=$this->references($form);$after=0;$count=0;$hash=hash_init('sha256');$deadline=BulkDeadline::started()+18;
  do{
   $page=$this->db->executeQuery('SELECT ids.file_id,f.storage,f.path,f.size,f.etag FROM ('.$sql.') ids LEFT JOIN *PREFIX*filecache f ON f.fileid=ids.file_id WHERE ids.file_id > ? ORDER BY ids.file_id LIMIT 100',[...$params,$after])->fetchAll();
   BulkDeadline::check();foreach($page as$row){if(++$count>1000||microtime(true)>$deadline||$row['etag']===null){throw new \RuntimeException('Managed file identity unavailable within preview budget');}$json=json_encode($row,JSON_THROW_ON_ERROR);if(strlen($json)>1048576){throw new \RuntimeException('Managed file identity exceeds projection budget');}hash_update($hash,$json);$after=(int)$row['file_id'];}
  }while(count($page)===100);
  $shareCursor='';$shareCount=0;
  do{
   $page=$this->db->executeQuery("SELECT m.files_share_id,m.native_share_id,m.folder_id,s.uid_owner,s.uid_initiator,s.share_type,s.share_with,s.permissions,f.storage,f.path FROM *PREFIX*forms_mcp_file_shares m LEFT JOIN *PREFIX*share s ON m.files_share_id = CONCAT('ocinternal:',s.id) LEFT JOIN *PREFIX*filecache f ON f.fileid=m.folder_id WHERE m.form_id = ? AND m.files_share_id > ? ORDER BY m.files_share_id LIMIT 100",[$form,$shareCursor])->fetchAll();
   foreach($page as$row){BulkDeadline::check();if(++$shareCount>BulkStore::MAX_ROWS){throw new \RuntimeException('Managed share preview exceeds row budget');}hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));if($row['uid_owner']!==null){hash_update($hash,json_encode(BulkTransferShares::shareState($row['files_share_id']),JSON_THROW_ON_ERROR));}$shareCursor=$row['files_share_id'];}
  }while(count($page)===100);
  $recipientCursor=0;
  do{
   $page=$this->db->executeQuery("SELECT c.id,c.parent,c.share_with,c.accepted,c.permissions,c.expiration,c.attributes FROM *PREFIX*share c INNER JOIN *PREFIX*forms_mcp_file_shares m ON m.files_share_id = CONCAT('ocinternal:',c.parent) WHERE m.form_id = ? AND c.share_type = 2 AND c.id > ? ORDER BY c.id LIMIT 100",[$form,$recipientCursor])->fetchAll();
   foreach($page as$row){BulkDeadline::check();if(++$shareCount>BulkStore::MAX_ROWS){throw new \RuntimeException('Recipient-state preview exceeds row budget');}$json=json_encode($row,JSON_THROW_ON_ERROR);if(strlen($json)>1040000){throw new \RuntimeException('Recipient state exceeds byte budget');}hash_update($hash,$json);$recipientCursor=(int)$row['id'];}
  }while(count($page)===100);
  return hash_final($hash);
 }
 public function deletes(string $action,int $form,int $fileId):bool {
  if($action==='forms_delete_form'){$delete=true;}else{$delete=$action==='forms_delete_responses'&&(bool)$this->db->executeQuery('SELECT a.id FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id = ? AND a.file_id = ? LIMIT 1',[$form,$fileId])->fetchOne();}
  if(!$delete){return false;}$owner=$this->db->executeQuery('SELECT owner_id FROM *PREFIX*forms_v2_forms WHERE id = ?',[$form])->fetchOne();return !LinkedMarkers::containsOutput($this->source(['form'=>['owner_id'=>$owner]],$fileId));
 }
 public function previewFiles(int $form,int $after,int $limit):array {
  [$sql,$params]=$this->references($form);$rows=$this->db->executeQuery('SELECT file_id FROM ('.$sql.') bulk_files WHERE file_id > ? ORDER BY file_id LIMIT '.$limit,[...$params,$after])->fetchAll();$owner=$this->db->executeQuery('SELECT owner_id FROM *PREFIX*forms_v2_forms WHERE id = ?',[$form])->fetchOne();$items=[];
  foreach($rows as$row){BulkDeadline::check();$file=$this->source(['form'=>['owner_id'=>$owner]],(int)$row['file_id']);StorageAdmission::node($file);$items[]=['file_id'=>(int)$file->getId(),'path'=>$file->getPath(),'name'=>$file->getName(),'size'=>(int)$file->getSize(),'etag'=>$file->getEtag()];}return $items;
 }
 public function metadata(array &$j):void {
  [$sql,$params]=$this->references((int)$j['form']['id']);$params[]=$j['cursor'];$id=$this->db->executeQuery('SELECT file_id FROM ('.$sql.') bulk_files WHERE file_id > ? ORDER BY file_id LIMIT 1',$params)->fetchOne();
  if($id===false){$j['phase']='backup_files';$j['cursor']=0;return;}
  $file=$this->source($j,(int)$id);OriginContext::assertFiles($j['action'],['files'=>[['file_id'=>(int)$id]]]);$file->lock(ILockingProvider::LOCK_SHARED);
  try{OriginContext::assertFiles($j['action'],['files'=>[['file_id'=>(int)$id]]]);$meta=$this->meta($file);$meta['delete']=$j['action']==='forms_delete_form'||($j['action']==='forms_delete_responses'&&(bool)$this->db->executeQuery('SELECT a.id FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id = ? AND a.file_id = ? LIMIT 1',[$j['form']['id'],$id])->fetchOne());$meta['delete']=$meta['delete']&&!LinkedMarkers::containsOutput($file);if($j['action']==='forms_delete_responses'&&$meta['delete']&&$this->db->executeQuery('SELECT file_id FROM *PREFIX*forms_mcp_artifacts WHERE form_id = ? AND file_id = ?',[$j['form']['id'],$id])->fetchOne()!==false){$approved=$this->store->one($j['id'],'artifacts',(int)$id);if((int)$approved['form_id']!==(int)$j['form']['id']){throw new \RuntimeException('Unapproved artifact reference');}$j['artifact_deletions']=($j['artifact_deletions']??0)+1;$meta['delete_artifact']=true;}BulkDeadline::check();$this->store->put($j,'files',(int)$id,$meta);$j['cursor']=(int)$id;}finally{$file->unlock(ILockingProvider::LOCK_SHARED);}
 }
 private function source(array $j,int $id):File {$f=Server::get(IRootFolder::class)->getUserFolder($j['form']['owner_id'])->getById($id)[0]??null;if(!$f instanceof File){throw new \RuntimeException('Approved managed file unavailable');}return $f;}
 public function meta(File $file):array {
  StorageAdmission::node($file);$size=(int)$file->getSize();$etag=$file->getEtag();if($size<0||$size>AnswerFiles::MAX_BYTES){throw new \RuntimeException('Managed file exceeds per-step byte budget');}
  $stream=$file->fopen('rb');if(!is_resource($stream)){throw new \RuntimeException('Managed file unreadable');}$start=BulkDeadline::started();$ctx=hash_init('sha256');$read=0;
  try{while(!feof($stream)){$chunk=fread($stream,65536);if($chunk===false){throw new \RuntimeException('Managed file read failed');}$read+=strlen($chunk);if($read>$size||microtime(true)-$start>18){throw new \RuntimeException('Managed file exceeded read budget');}hash_update($ctx,$chunk);}}finally{fclose($stream);}
  if($read!==$size||$etag!==$file->getEtag()){throw new \RuntimeException('Managed file changed');}
  return ['file_id'=>(int)$file->getId(),'path'=>$file->getPath(),'name'=>$file->getName(),'etag'=>$etag,'size'=>$size,'sha256'=>hash_final($ctx)];
 }
 public function next(array $j):?array {$id=$j['action']==='forms_restore'?$j['input']['backup_id']:$j['id'];$rows=$this->store->page($id,$j['phase']==='stage_recipients'?'share_recipients':(in_array($j['phase'],['remove_shares','stage_shares'],true)?'managed_shares':'files'),$j['cursor']);return $rows[0]??null;}
 public function reservation(array $j,array $row):int {return in_array($j['phase'],['backup_files','copy_files','restore_files'],true)?(int)$row['data']['size']:0;}
 public function manifest(array $j,array $meta):array {if($j['action']==='forms_restore'){return ['files'=>[],'restore'=>'new_private_closed_form','destination_owner'=>$j['uid'],'backup_id'=>$j['input']['backup_id']];}return ['form_id'=>(int)$j['form']['id'],'target_owner'=>$j['input']['owner_id']??null,'files'=>[$meta],'delete_file_ids'=>$meta['delete']?[$meta['file_id']]:[],'files_share'=>false,'share_removals'=>[],'share_impact'=>[],'folders'=>'retained','recovery'=>true];}
 public function effect(array &$j,array $row,?callable $boundary):void {
  $deadline=BulkDeadline::started()+18;$original=$boundary;$target=$j['action']==='forms_transfer'?$j['input']['owner_id']:null;$boundary=static function(string $action,array $manifest)use($deadline,$original,$target,$j):void{if(microtime(true)>$deadline){throw new \RuntimeException('Bulk file step deadline exhausted');}if($original!==null){$original($action,$manifest);}if($target!==null){(new FreshCore($target))->assertPrincipal(false,false);if(!hash_equals($j['target_principal'],\OCA\Forms\Mcp\Comments\CommentsRuntime::principal($target))){throw new \RuntimeException('Transfer target incarnation changed');}$form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$j['form']['id']);if($form->getOwnerId()!==$j['uid']||$form->getState()===2){throw new \RuntimeException('Original transfer owner authority changed');}}if(microtime(true)>$deadline){throw new \RuntimeException('Bulk file step deadline exhausted');}if(microtime(true)>$deadline){throw new \RuntimeException('Bulk file step deadline exhausted after policy wait');}};
  $m=$row['data'];if($j['phase']==='stage_recipients'){Server::get(BulkTransferShares::class)->stageRecipient($j,$row,$boundary);return;}if($j['phase']==='stage_shares'){Server::get(BulkTransferShares::class)->stage($j,$row,$boundary);return;}if($j['phase']==='remove_shares'){$this->removeShare($j,$row,$boundary);return;}$manifest=$this->manifest($j,$m);
  OriginContext::withFileBoundary($boundary,function()use(&$j,$manifest,$m,$row){return OriginContext::withFileImpact($j['action'],$manifest,function()use(&$j,$m,$row,$manifest):void{
   OriginContext::assertFresh();OriginContext::assertFiles($j['action'],$manifest);StorageAdmission::bulkRecovery();
   if($j['phase']==='backup_files'){
    $source=$this->source($j,$row['id']);$source->lock(ILockingProvider::LOCK_SHARED);
    try{$actual=$this->meta($source);$expected=$m;unset($expected['delete'],$expected['delete_artifact']);if($actual!==$expected){throw new \RuntimeException('Approved managed file changed');}
     $folder=NewFileStorage::folder(Server::get(IRootFolder::class),StorageAdmission::bulkRecovery().'/'.$j['id']);
     $stream=$source->fopen('rb');try{OriginContext::assertFileEffect();$copy=NewFileStorage::create($folder,'file-'.$row['id'],$stream);}finally{if(is_resource($stream)){fclose($stream);}}
     $this->verifyCopy($copy,$m);
    }finally{$source->unlock(ILockingProvider::LOCK_SHARED);}
   }elseif($j['phase']==='delete_files'){
    if(!$m['delete']){return;}$saved=Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('forms_mcp_bulk_recovery')->getFolder($j['id'])->getFile('file-'.$row['id']);$this->verifyCopy($saved,$m);$file=$this->source($j,$row['id']);$wrapper=null;$path=$file->getInternalPath();$storage=$file->getStorage();
    $file->getMountPoint()->wrapStorage(function($path,$storage)use($file,$m,&$wrapper){$wrapper=new ManagedFileStorage($storage,[$file->getInternalPath()=>$m]);return $wrapper;});
    try{$file->delete();if($storage->file_exists($path)){throw new \RuntimeException('Exact managed deletion unverified');}OriginContext::verifiedDeletion($row['id']);if($m['delete_artifact']??false){$approved=$this->store->one($j['id'],'artifacts',$row['id']);$count=$this->db->executeStatement('DELETE FROM *PREFIX*forms_mcp_artifacts WHERE form_id = ? AND file_id = ?',[$approved['form_id'],$row['id']]);if($count!==1){throw new \RuntimeException('Exact artifact reference removal mismatch');}$j['done']++;}}finally{if($wrapper){$wrapper->enabled=false;}}
   }else{
    $owner=$j['phase']==='restore_files'?$j['uid']:$j['input']['owner_id'];$root=Server::get(IRootFolder::class)->getUserFolder($owner);
    $form=$j['phase']==='restore_files'?Server::get(\OCA\Forms\Db\FormMapper::class)->findById($j['restored_form_id']):Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$j['form']['id']);
    $base=Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);$path=$base.'/bulk-'.$j['id'];
    if($j['cursor']===0&&$root->nodeExists($base)){throw new \RuntimeException('Bulk copy destination already exists');}
    $name=$row['id'].'-'.$m['name'];if($j['phase']==='copy_files'){$full=BulkTransferShares::path($j,$m['path']);$path=dirname($full);$name=basename($full);}
    $folder=NewFileStorage::folder($root,$path);$backup=$j['phase']==='restore_files'?$j['input']['backup_id']:$j['id'];$saved=Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('forms_mcp_bulk_recovery')->getFolder($backup)->getFile('file-'.$row['id']);$this->verifyCopy($saved,$m);
    $stream=$saved->read();try{$copy=NewFileStorage::create($folder,$name,$stream);}finally{if(is_resource($stream)){fclose($stream);}}$this->verifyCopy($copy,$m);
    if($j['phase']==='restore_files'){$this->store->put($j,'files',$row['id'],[]);$this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_artifacts (form_id,file_id) VALUES (?,?)',[$j['restored_form_id'],$copy->getId()]);}
    $this->store->map($j['id'],'files',$row['id'],(int)$copy->getId());if($j['phase']==='copy_files'){$copyMeta=$this->meta($copy);$copyMeta['cache']=$this->db->executeQuery('SELECT storage,path,size,etag FROM *PREFIX*filecache WHERE fileid = ?',[$copy->getId()])->fetch();$this->store->putMapping($j,'copies',$row['id'],$copyMeta);}
   }
  });});if(microtime(true)>$deadline){throw new \RuntimeException('Bulk file step deadline exhausted after effect');}
 }
 private function verifyCopy(object $copy,array $meta):void {
  BulkDeadline::check();if((int)$copy->getSize()!==$meta['size']){throw new \RuntimeException('Recovery copy size changed');}
  $stream=$copy instanceof File?$copy->fopen('rb'):$copy->read();if(!is_resource($stream)){throw new \RuntimeException('Recovery copy unreadable');}$ctx=hash_init('sha256');$size=0;
  try{while(!feof($stream)){BulkDeadline::check();$chunk=fread($stream,65536);if($chunk===false){throw new \RuntimeException('Recovery copy read failed');}$size+=strlen($chunk);if($size>$meta['size']){throw new \RuntimeException('Recovery copy grew');}hash_update($ctx,$chunk);}}finally{fclose($stream);}
  BulkDeadline::check();if($size!==$meta['size']||!hash_equals($meta['sha256'],hash_final($ctx))){throw new \RuntimeException('Recovery copy verification failed');}
 }
 private function removeShare(array &$j,array $row,?callable $boundary):void {
  $r=$row['data'];OriginContext::assertFresh();if(!in_array('share',OriginContext::current()['verified']['references']['files_operations']??[],true)){throw new \RuntimeException('Original Files-sharing grant unavailable');}$manager=SharingIdentity::manager();
  try{$share=$manager->getShareById($r['files_share_id'],onlyValid:false);}catch(\OCP\Share\Exceptions\ShareNotFound){if(!ManagedShares::isAbsent($r['files_share_id'])){throw new \RuntimeException('Managed share absence unknown');}$this->db->executeStatement('DELETE FROM *PREFIX*forms_mcp_file_shares WHERE files_share_id = ?',[$r['files_share_id']]);$j['done']++;return;}
  $folder=Server::get(IRootFolder::class)->getUserFolder($j['form']['owner_id'])->getById((int)$r['folder_id'])[0]??null;
  if(!$folder instanceof Folder){throw new \RuntimeException('Managed share folder unavailable');}
  $intent=$r['approved_tuple'];SharingIdentity::assertType($intent['type']);ManagedShares::assertIdentity($share,$intent,$r);
  $manifest=['files'=>[],'files_share'=>true,'share_removals'=>[['parent'=>['file_id'=>(int)$folder->getId()],'folder_ids'=>[(int)$folder->getId()],'managed_shares'=>[$r],'recipient'=>$intent['recipient'],'type'=>$intent['type']]]];
  OriginContext::withFileBoundary($boundary,function()use(&$j,$manifest,$folder,$intent,$r):void {OriginContext::withFileImpact($j['action'],$manifest,function()use(&$j,$folder,$intent,$r):void {
   $folder->lock(ILockingProvider::LOCK_EXCLUSIVE);
   try{
    $authorize=function($share,bool $create)use($j,$folder,$intent,$r):void{OriginContext::assertFileEffect([(int)$folder->getId()]);StorageAdmission::node($folder);$f=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$j['form']['id']);if($create||$f->getOwnerId()!==$j['uid']){throw new \RuntimeException('Managed share authority changed');}ManagedShares::assertIdentity($share,$intent,$r);};
    $manager=ScopedShareManager::create($j['uid'],$authorize);$share=$manager->getShareById($r['files_share_id'],onlyValid:false);$authorize($share,false);$manager->deleteShare($share);
    if(!ManagedShares::isAbsent($r['files_share_id'])){throw new \RuntimeException('Managed share removal unverified');}
    $this->db->executeStatement('DELETE FROM *PREFIX*forms_mcp_file_shares WHERE files_share_id = ?',[$r['files_share_id']]);$j['done']++;$j['share_events']=true;$j['event_phase']='attempted_unverified';
   }finally{$folder->unlock(ILockingProvider::LOCK_EXCLUSIVE);}
  });});
 }
 public function commitTransfer(array $j,float $start,callable $commit):int {
  $locked=[];Server::get(FormsRevisionFence::class)->afterTransaction(function()use(&$locked):void{foreach(array_reverse($locked)as$file){$file->unlock(ILockingProvider::LOCK_SHARED);}$locked=[];});$target=Server::get(IRootFolder::class)->getUserFolder($j['input']['owner_id']);$cursor=0;
  try{
   while($rows=$this->store->page($j['id'],'files',$cursor)){
    foreach($rows as$r){
     if(microtime(true)-$start>18){throw new \OCA\Forms\Mcp\BeforeEffect('Transfer file verification budget exhausted');}
     $cursor=$r['id'];$source=$this->source($j,$r['id']);$source->lock(ILockingProvider::LOCK_SHARED);$locked[]=$source;
     $copyId=$this->store->mapped($j['id'],'files',$r['id']);$copy=$target->getById($copyId)[0]??null;
     if(!$copy instanceof File){throw new \OCA\Forms\Mcp\BeforeEffect('Verified transfer copy is unavailable');}$copy->lock(ILockingProvider::LOCK_SHARED);$locked[]=$copy;
     OriginContext::assertFresh();StorageAdmission::node($source);StorageAdmission::node($copy);$meta=$this->store->one($j['id'],'copies',$r['id']);
     $cache=$this->db->executeQuery('SELECT storage,path,size,etag FROM *PREFIX*filecache WHERE fileid = ?',[$copyId])->fetch();
     OriginContext::assertFiles($j['action'],$this->manifest($j,$r['data'])+['destination'=>['owner'=>$j['input']['owner_id'],'file_id'=>$copyId,'path'=>$meta['path']]]);
     if($cache!==$meta['cache']||$copy->getPath()!==$meta['path']||$copy->getEtag()!==$meta['etag']||(int)$copy->getSize()!==$meta['size']||$copy->getOwner()?->getUID()!==$j['input']['owner_id']){throw new \OCA\Forms\Mcp\BeforeEffect('Verified transfer copy changed before ownership switch');}
    }
   }
   if(!hash_equals($j['file_revision'],$this->fingerprint((int)$j['form']['id']))||microtime(true)-$start>18){throw new \OCA\Forms\Mcp\BeforeEffect('Transfer source changed or verification budget exhausted');}
   $this->db->executeQuery('SELECT uid FROM *PREFIX*users WHERE uid = ? FOR UPDATE',[$j['input']['owner_id']])->fetchOne();(new FreshCore($j['input']['owner_id']))->assertPrincipal(false,false);if(!hash_equals($j['target_principal']??'',\OCA\Forms\Mcp\Comments\CommentsRuntime::principal($j['input']['owner_id']))){throw new \OCA\Forms\Mcp\BeforeEffect('Approved transfer target incarnation changed');}return $commit();
  }finally{/* Locks remain owned until the surrounding native transaction commits or rolls back. */}
 }
 public function remap(array &$j,float $start):void {
  foreach(['answers','uploads','artifacts']as$kind){$cursor=0;while($rows=$this->store->page($j['id'],$kind,$cursor)){foreach($rows as$r){if(microtime(true)-$start>18){throw new \OCA\Forms\Mcp\BeforeEffect('Transfer mapping budget exhausted; verified copies retained');}$cursor=$r['id'];$old=(int)($r['data']['file_id']??0);if(!$old){continue;}$new=$this->store->mapped($j['id'],'files',$old);$table=match($kind){'answers'=>'forms_v2_answers','uploads'=>'forms_v2_uploaded_files','artifacts'=>'forms_mcp_artifacts'};$pk=$kind==='artifacts'?'file_id':'id';$count=$this->db->executeStatement('UPDATE *PREFIX*'.$table.' SET file_id = ? WHERE '.$pk.' = ? AND file_id = ?',[$new,$r['id'],$old]);if($count!==1){throw new \RuntimeException('Exact transfer reference changed');}}}}
 }
}
