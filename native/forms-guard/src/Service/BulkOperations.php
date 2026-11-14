<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCA\Forms\Mcp\BeforeEffect;
use OCP\{Server,IDBConnection};
/** Server-only bounded destructive protocol. Checkpoints and exact SQL effects commit together. */
final class BulkOperations {
 public const ACTIONS=['forms_delete_responses','forms_delete_form','forms_transfer','forms_restore'];
 private const KINDS=['form','questions','options','submissions','answers','uploads','artifacts','shares','comments','managed_shares'];
 private const TABLES=['form'=>'forms_v2_forms','questions'=>'forms_v2_questions','options'=>'forms_v2_options','submissions'=>'forms_v2_submissions','answers'=>'forms_v2_answers','uploads'=>'forms_v2_uploaded_files','artifacts'=>'forms_mcp_artifacts','shares'=>'forms_v2_shares','comments'=>'comments','managed_shares'=>'forms_mcp_file_shares'];
 public function __construct(private IDBConnection $db,private FormsRevisionFence $fence,private BulkStore $store){}
 private function source(string $kind,int $id):array {
  return match($kind){
   'form'=>['SELECT x.* FROM *PREFIX*forms_v2_forms x WHERE x.id = ?',[$id],'x.id'],
   'answers'=>['SELECT x.* FROM *PREFIX*forms_v2_answers x JOIN *PREFIX*forms_v2_submissions s ON s.id=x.submission_id WHERE s.form_id = ?',[$id],'x.id'],
   'options'=>['SELECT x.* FROM *PREFIX*forms_v2_options x JOIN *PREFIX*forms_v2_questions q ON q.id=x.question_id WHERE q.form_id = ?',[$id],'x.id'],
   'managed_shares'=>['SELECT x.*, '.($this->db->getDatabaseProvider(true)==='postgres'?'CAST(SUBSTRING(x.files_share_id,12) AS BIGINT)':'CAST(SUBSTRING(x.files_share_id,12) AS SIGNED)').' AS id FROM *PREFIX*forms_mcp_file_shares x WHERE x.form_id = ?',[$id],($this->db->getDatabaseProvider(true)==='postgres'?'CAST(SUBSTRING(x.files_share_id,12) AS BIGINT)':'CAST(SUBSTRING(x.files_share_id,12) AS SIGNED)')],
   'comments'=>["SELECT x.* FROM *PREFIX*comments x WHERE x.object_type = 'forms' AND x.object_id = ?",[(string)$id],'x.id'],
   'artifacts'=>['SELECT x.* FROM *PREFIX*forms_mcp_artifacts x WHERE x.form_id = ?',[$id],'x.file_id'],
   default=>['SELECT x.* FROM *PREFIX*'.self::TABLES[$kind].' x WHERE x.form_id = ?',[$id],'x.id'],
  };
 }
 private function counts(int $id):array {$out=[];foreach(self::KINDS as$kind){[$sql,$params]=$this->source($kind,$id);$out[$kind]=(int)$this->db->executeQuery('SELECT COUNT(*) FROM ('.$sql.') bulk_count',$params)->fetchOne();}$out['files']=Server::get(BulkFiles::class)->count($id);return $out;}
 private static function key(string $key):void {if(!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$key)){throw new BeforeEffect('Invalid bulk operation key');}}
 private function admit(string $uid,string $action,array $input,callable $authorize):void {
  if(!BulkStore::ready()||!in_array($action,self::ACTIONS,true)){throw new BeforeEffect('Native bounded lifecycle unavailable');}
  OriginContext::assertFresh();if(strlen(json_encode($input,JSON_THROW_ON_ERROR))>1048576){throw new BeforeEffect('Bulk input exceeds budget');}
  if($action==='forms_restore'){Server::get(\OCA\Forms\Mcp\Guard::class)->assertPrincipal($uid);if(Server::get(\OCP\IUserSession::class)->getUser()?->getUID()!==$uid){throw new BeforeEffect('Restore actor unavailable');}$this->recovery($uid,(string)$input['backup_id']);return;}
  $authorize();$form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$input['id']);
  if($action==='forms_delete_form'&&(int)$this->db->executeQuery("SELECT COUNT(*) FROM *PREFIX*comments WHERE object_type = 'forms' AND object_id = ?",[(string)$input['id']])->fetchOne()>0&&!Server::get(\OCA\Forms\Mcp\Guard::class)->capabilities()['comments']){throw new BeforeEffect('Whole-form comment cleanup requires the activated native comments guard');}
  if($form->getState()===2){throw new BeforeEffect('Archived form is unavailable for bulk mutation');}
  if(in_array($action,['forms_delete_form','forms_transfer'],true)&&$form->getOwnerId()!==$uid){throw new BeforeEffect('Only the current form owner may perform this operation');}
  if($action==='forms_transfer'&&(int)$this->db->executeQuery("SELECT COUNT(*) FROM *PREFIX*forms_mcp_file_shares m INNER JOIN *PREFIX*share s ON m.files_share_id = CONCAT('ocinternal:',s.id) WHERE m.form_id = ?",[$input['id']])->fetchOne()>0){throw new BeforeEffect('Transfer with live managed Files shares awaits qualified target-share staging');}
  if($action==='forms_transfer'){(new FreshCore($input['owner_id']??''))->assertPrincipal(false,false);$target=Server::get(\OCP\IUserManager::class)->get($input['owner_id']??'');if(!$target||!$target->isEnabled()){throw new BeforeEffect('Transfer target unavailable');}}
 }
 public function snapshot(string $uid,string $action,array $input,callable $authorize):array {
  $rev=$this->fence->revision();$this->admit($uid,$action,$input,$authorize);
  if($action==='forms_restore'){$backup=$this->recovery($uid,$input['backup_id']);$counts=$backup['counts'];$form=$backup['form'];}
  else{$counts=$this->counts((int)$input['id']);$form=$this->db->executeQuery('SELECT id,owner_id,title FROM *PREFIX*forms_v2_forms WHERE id = ?',[$input['id']])->fetch();}
  if(array_sum($counts)>BulkStore::MAX_ROWS||$counts['files']>1000){throw new BeforeEffect('Bulk recovery row quota exceeded');}
  if($action==='forms_transfer'&&$counts['files']>0){$native=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$input['id']);$path=Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($native);if(Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($input['owner_id'])->nodeExists($path)){throw new BeforeEffect('Transfer destination already exists');}}
  if($rev!==$this->fence->revision()){throw new BeforeEffect('Forms changed during bulk preview');}
  return ['revision'=>'b1:'.$rev.':'.($action==='forms_restore'?str_repeat('0',64):Server::get(BulkFiles::class)->fingerprint((int)$form['id'])),'action'=>$action,'form'=>['id'=>(int)$form['id'],'title'=>$form['title'],'ownerId'=>$form['owner_id']], 'counts'=>$counts,'file_manifest'=>['files_share'=>($counts['managed_shares']??0)>0],'source_epoch'=>BulkEpoch::epoch((int)$form['id']),'target'=>$input['owner_id']??null,'destination'=>$this->destination($uid,$action,$input),'preview_collections'=>['files','managed_shares'],'recovery'=>'complete_sealed_before_effect','files'=>'exact_managed_ids_only','folders'=>'retained','linked_outputs'=>'retained'];
 }
 /** Read-only inspection of every approved external effect, bound to the preview revision. */
 public function previewPage(string $uid,string $action,array $input,string $revision,string $kind,int $cursor,int $limit,callable $authorize):array {
  if(!in_array($kind,['files','managed_shares'],true)||$cursor<0||$limit<1||$limit>100){throw new BeforeEffect('Invalid bounded preview page');}
  $snapshot=$this->snapshot($uid,$action,$input,$authorize);if(!hash_equals($revision,$snapshot['revision'])){throw new BeforeEffect('Bulk preview stale');}
  $backup=$action==='forms_restore'?$this->recovery($uid,$input['backup_id']):null;$items=[];$bytes=0;$after=$cursor;
  if($kind==='files'){
   $rows=$backup?$this->store->page($backup['id'],'files',$cursor):Server::get(BulkFiles::class)->previewFiles((int)$input['id'],$cursor,$limit);
   foreach(array_slice($rows,0,$limit)as$row){
    BulkDeadline::check();$m=$backup?$row['data']:$row;$id=(int)($backup?$row['id']:$m['file_id']);
    $effect=in_array($action,['forms_transfer','forms_restore'],true)?'copy':(Server::get(BulkFiles::class)->deletes($action,(int)$input['id'],$id)?'delete':'retain');
    $item=['file_id'=>$id,'path'=>$m['path'],'name'=>$m['name'],'size'=>(int)$m['size'],'etag'=>$m['etag'],'owner'=>$snapshot['form']['ownerId'],'action'=>$effect,'destination'=>$effect==='copy'?$snapshot['destination']:null,'recovery'=>'retained_private_copy_before_source_effect'];
    $size=strlen(json_encode($item,JSON_THROW_ON_ERROR));if($size>1040000){throw new BeforeEffect('Preview item exceeds byte budget');}if($bytes+$size>1040000){break;}$bytes+=$size;$items[]=$item;$after=$id;
   }
  }else{
   if($backup){$rows=$this->store->page($backup['id'],'managed_shares',$cursor);}else{[$sql,$params,$pk]=$this->source('managed_shares',(int)$input['id']);$rows=$this->db->executeQuery($sql.' AND '.$pk.' > ? ORDER BY '.$pk.' LIMIT '.$limit,[...$params,$cursor])->fetchAll();}
   foreach(array_slice($rows,0,$limit)as$row){
    BulkDeadline::check();$r=$backup?$row['data']:$row;$id=(int)($backup?$row['id']:$r['id']);
    if($backup){$tuple=$r['approved_tuple'];}else{try{$share=Server::get(\OCA\Forms\Db\ShareMapper::class)->findById((int)$r['native_share_id']);}catch(\OCP\AppFramework\Db\DoesNotExistException){$share=ManagedShares::historicalShare((int)$input['id'],(int)$r['native_share_id']);}$tuple=['owner_id'=>$snapshot['form']['ownerId'],'recipient'=>$share->getShareWith(),'type'=>$share->getShareType()];if(!ManagedShares::isAbsent($r['files_share_id'])){ManagedShares::assertIdentity(SharingIdentity::manager()->getShareById($r['files_share_id'],onlyValid:false),$tuple,$r);}}
    $item=['native_share_id'=>(int)$r['native_share_id'],'recipient'=>$tuple['recipient'],'type'=>$tuple['type'],'folder_ids'=>[(int)$r['folder_id']],'managed_share_ids'=>[$r['files_share_id']],'action'=>$action==='forms_delete_form'?'remove':($backup?'not_recreated':'retain')];
    $size=strlen(json_encode($item,JSON_THROW_ON_ERROR));if($size>1040000){throw new BeforeEffect('Preview item exceeds byte budget');}if($bytes+$size>1040000){break;}$bytes+=$size;$items[]=$item;$after=$id;
   }
  }
  if($backup){$more=$this->store->hasAfter($backup['id'],$kind,$after);}elseif($kind==='files'){$more=Server::get(BulkFiles::class)->previewFiles((int)$input['id'],$after,1)!==[];}else{[$sql,$params,$pk]=$this->source($kind,(int)$input['id']);$more=$this->db->executeQuery($sql.' AND '.$pk.' > ? ORDER BY '.$pk.' LIMIT 1',[...$params,$after])->fetchOne()!==false;}
  $this->admit($uid,$action,$input,$authorize);$current='b1:'.$this->fence->revision().':'.($backup?str_repeat('0',64):Server::get(BulkFiles::class)->fingerprint((int)$input['id']));BulkDeadline::check();if(!hash_equals($revision,$current)){throw new BeforeEffect('Bulk preview changed during inspection');}
  return ['items'=>$items,'total'=>(int)$snapshot['counts'][$kind],'next_cursor'=>$more?$after:null,'complete'=>!$more,'revision'=>$revision];
 }
 private function destination(string $uid,string $action,array $input):?array {
  if(!in_array($action,['forms_transfer','forms_restore'],true)){return null;}
  if($action==='forms_transfer'){$form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$input['id']);$base=Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);}
  else{$base=\OCA\Forms\Constants::FILES_FOLDER.'/{new_form_id} - {normalized_recovered_title}';}
  return ['owner'=>$input['owner_id']??$uid,'base'=>$base,'path_template'=>$base.'/bulk-{operation_id}/{source_file_id}-{source_name}','overwrite'=>false,'form'=>$action==='forms_restore'?'new_private_closed':'same_form'];
 }
 public function start(string $uid,string $action,array $input,string $revision,string $key,callable $authorize):array {
  self::key($key);if(!preg_match('/^b1:([0-9]+):([a-f0-9]{64})$/D',$revision,$parts)){throw new BeforeEffect('Bulk preview identity invalid');}$fileRevision=$parts[2];$revision=$parts[1];$intent=$input;unset($intent['_mcp_origin']);$fingerprint=BulkStore::digest([$action,$intent,OriginContext::identity($uid)]);
  return $this->fence->withLock(function()use($uid,$action,$input,$revision,$key,$authorize,$fingerprint,$fileRevision):array{
   $old=$this->store->byKey($key);if($old){$this->actor($uid,$old);if(!hash_equals($fingerprint,$old['fingerprint'])){throw new BeforeEffect('Bulk key conflict');}return $this->dto($old);}
   return $this->fence->transaction(function()use($uid,$action,$input,$revision,$key,$authorize,$fingerprint,$fileRevision):array{
   if((string)((int)$this->fence->revision()-1)!==$revision){throw new BeforeEffect('Bulk preview stale');}
   $this->admit($uid,$action,$input,$authorize);if($action!=='forms_restore'&&!hash_equals($fileRevision,Server::get(BulkFiles::class)->fingerprint((int)$input['id']))){throw new BeforeEffect('Managed Files changed after preview');}$backup=$action==='forms_restore'?$this->recovery($uid,$input['backup_id']):null;
   $counts=$backup['counts']??$this->counts((int)$input['id']);if(array_sum($counts)>BulkStore::MAX_ROWS||$counts['files']>1000){throw new BeforeEffect('Bulk recovery row quota exceeded');}
   $form=$backup['form']??$this->db->executeQuery('SELECT * FROM *PREFIX*forms_v2_forms WHERE id = ?',[$input['id']])->fetch();
   $j=['id'=>hash('sha256','forms-bulk:'.$key),'key'=>$key,'actor'=>OriginContext::identity($uid),'uid'=>$uid,'action'=>$action,'input'=>$input,'fingerprint'=>$fingerprint,'origin'=>OriginContext::current()['origin'],'revision'=>$this->fence->revision(),'backup_format'=>1,'file_revision'=>$fileRevision,'epoch'=>$backup?0:BulkEpoch::epoch((int)$form['id']),'global_epoch'=>BulkEpoch::epoch(0),'native_manifest'=>BulkEpoch::runtime(),'form'=>$form,'counts'=>$counts,'state'=>'pending','phase'=>$backup?'restore':'capture','kind'=>0,'cursor'=>0,'version'=>1,'captured'=>0,'done'=>0,'bytes'=>0,'chain'=>'','verified_chain'=>'','verified'=>0,'sealed'=>false,'restored_form_id'=>null];
   $this->store->create($j);BulkDeadline::check();return $this->dto($j);
   });
  });
 }
 private function sourceEpoch(array $j):int {return $j['action']==='forms_restore'?($j['restored_form_id']===null?0:BulkEpoch::epoch((int)$j['restored_form_id'])):BulkEpoch::epoch((int)$j['form']['id']);}
 private function sourceMatches(array $j):bool {return hash_equals($j['native_manifest']??'',BulkEpoch::runtime())&&($j['global_epoch']??-1)===BulkEpoch::epoch(0)&&$j['epoch']===$this->sourceEpoch($j);}
 private function actor(string $uid,array $j):void {if(!hash_equals($j['actor'],OriginContext::identity($uid))||$uid!==$j['uid']){throw new BeforeEffect('Bulk operation unavailable to this actor');}}
 public function lookup(string $uid,string $key):?array {$j=$this->store->byKey($key);if(!$j){return null;}$this->actor($uid,$j);return OriginContext::within($uid,$j['origin'],'lookup',$j['input'],fn()=> $this->dto($j));}
 public function status(string $uid,string $id):array {$j=$this->store->load($id);$this->actor($uid,$j);return OriginContext::within($uid,$j['origin'],'lookup',$j['input'],fn()=> $this->dto($j));}
 public function step(string $uid,string $id,int $version,string $key,callable $authorize,?callable $files=null):array {
  self::key($key);$j=$this->store->load($id);$this->actor($uid,$j);
  return OriginContext::within($uid,$j['origin'],$j['action'],$j['input'],fn()=> $this->fence->withLock(function()use($uid,$id,$version,$key,$authorize,$files):array {
   $j=$this->store->load($id);$this->actor($uid,$j);$prior=$this->store->receipt($id,$key);if($prior!==null){return $prior;}
   if($j['version']!==$version){throw new BeforeEffect('Bulk version conflict');}
   if(!in_array($j['state'],['pending'],true)){return $this->dto($j);}
   if(in_array($j['phase'],['backup_files','copy_files','delete_files','restore_files','remove_shares'],true)){
    return $this->fileStep($j,$uid,$key,$authorize,$files);
   }
   return $this->fence->transaction(function()use(&$j,$uid,$key,$authorize,$files):array {
    if(!$this->sourceMatches($j)){$j['state']=($j['done']>0||($j['source_effects']??false)||array_sum($j['retained_files']??[])>0)?'partial':'failed_before_effect';$j['reason']='source_changed';}
    else {
     $this->admit($uid,$j['action'],$j['input'],$authorize);if(!$j['sealed']&&$j['action']!=='forms_restore'&&!hash_equals($j['file_revision'],Server::get(BulkFiles::class)->fingerprint((int)$j['form']['id']))){throw new BeforeEffect('Managed Files changed before recovery seal');}
     $started=BulkDeadline::started();BulkDeadline::check();
     match($j['phase']){
      'capture'=>$this->capture($j,$started),
      'verify'=>$this->verify($j,$started),
      'file_metadata'=>OriginContext::withFileBoundary($files,function()use(&$j){Server::get(BulkFiles::class)->metadata($j);}),
      'apply'=>OriginContext::withFileBoundary($files,function()use(&$j,$started){$this->applyPage($j,$started);}),
      'restore'=>$this->restorePage($j,$started),
      default=>throw new \RuntimeException('Unknown bulk phase'),
     };
    }
    BulkDeadline::check();$j['version']++;$j['revision']=$this->fence->revision();$j['epoch']=$this->sourceEpoch($j);$this->store->save($j);$dto=$this->dto($j);$this->store->record($j['id'],$key,$dto);BulkDeadline::check();return $dto;
   });
  }));
 }
 private function fileStep(array $j,string $uid,string $key,callable $authorize,?callable $files):array {
  if(!$this->sourceMatches($j)){return $this->fence->transaction(function()use(&$j,$key):array{$j['state']=($j['done']>0||($j['source_effects']??false)||array_sum($j['retained_files']??[])>0)?'partial':'failed_before_effect';$j['reason']='source_changed';$j['version']++;$this->store->save($j);$dto=$this->dto($j);$this->store->record($j['id'],$key,$dto);return $dto;});}
  $worker=Server::get(BulkFiles::class);$row=$worker->next($j);
  $this->fence->transaction(function()use(&$j,$uid,$authorize,$worker,$row,$key):void{
   if(!$this->sourceMatches($j)){throw new BeforeEffect('Bulk source changed');}
   $this->admit($uid,$j['action'],$j['input'],$authorize);if($j['phase']==='backup_files'&&!hash_equals($j['file_revision'],$worker->fingerprint((int)$j['form']['id']))){throw new BeforeEffect('Managed Files changed before recovery seal');}
   if($row===null){
    $j['phase']=match($j['phase']){'backup_files'=>'verify','restore_files'=>'restore','remove_shares'=>'delete_files',default=>'apply'};$j['cursor']=0;
   }else{
    BulkDeadline::check();
    // Reservation survives an unknown external write, including a lost acknowledgement.
    $this->store->reserve($j,$worker->reservation($j,$row));$j['state']='uncertain';if($j['phase']==='remove_shares'){$j['share_events']=true;$j['event_phase']='uncertain';}$j['claim']=['step_key'=>$key,'file_id'=>$row['id'],'phase'=>$j['phase']];
   }
   $j['version']++;$j['revision']=$this->fence->revision();$j['epoch']=$this->sourceEpoch($j);$this->store->save($j);if($row===null){$this->store->record($j['id'],$key,$this->dto($j));}BulkDeadline::check();
  });
  if($row===null){return $this->dto($j);}
  try {
   return $this->fence->transaction(function()use(&$j,$uid,$key,$authorize,$worker,$row,$files):array{
    $this->admit($uid,$j['action'],$j['input'],$authorize);$beforeEpoch=$this->sourceEpoch($j);$globalEpoch=BulkEpoch::epoch(0);$worker->effect($j,$row,$files);BulkDeadline::check();if(in_array($j['phase'],['backup_files','copy_files','restore_files'],true)){$kind=$j['phase']==='backup_files'?'recovery':'copies';$j['retained_files'][$kind]=($j['retained_files'][$kind]??0)+1;}if($beforeEpoch!==$this->sourceEpoch($j)||$globalEpoch!==BulkEpoch::epoch(0)){throw new \RuntimeException('Native source changed during Files callbacks');}if(in_array($j['phase'],['delete_files','remove_shares','restore_files'],true)){BulkEpoch::touch((int)($j['action']==='forms_restore'?$j['restored_form_id']:$j['form']['id']));}OriginContext::assertFresh();if($j['phase']==='delete_files'&&$row['data']['delete']){$j['source_effects']=true;$j['files_done']=($j['files_done']??0)+1;}
    $j['cursor']=$row['id'];$j['state']='pending';unset($j['claim']);$j['revision']=$this->fence->revision();$j['epoch']=$this->sourceEpoch($j);$this->store->save($j);$dto=$this->dto($j);$this->store->record($j['id'],$key,$dto);BulkDeadline::check();return $dto;
   });
  }catch(\Throwable){return $this->dto($this->store->load($j['id']));}
 }
 public function cancel(string $uid,string $id,int $version,string $key):array {
  self::key($key);$original=$this->store->load($id);$this->actor($uid,$original);return OriginContext::within($uid,$original['origin'],'lookup',$original['input'],fn()=> $this->fence->transaction(function()use($uid,$id,$version,$key):array{$j=$this->store->load($id);$this->actor($uid,$j);$prior=$this->store->receipt($id,$key);if($prior){return $prior;}if($version!==$j['version']){throw new BeforeEffect('Bulk version conflict');}if($j['state']==='pending'){$j['state']=($j['done']>0||($j['source_effects']??false)||array_sum($j['retained_files']??[])>0)?'partial':'cancelled';$j['version']++;$this->store->save($j);}$dto=$this->dto($j);$this->store->record($id,$key,$dto);return $dto;}));
 }
 // Yield ordinary row pages early enough to persist their cursor before the hard18s deadline.
 private function capture(array &$j,float $start):void {
  $kind=self::KINDS[$j['kind']];[$sql,$params,$pk]=$this->source($kind,(int)$j['form']['id']);$params[]=$j['cursor'];$result=$this->db->executeQuery($sql.' AND '.$pk.' > ? ORDER BY '.$pk.' LIMIT 100',$params);$bytes=0;$processed=0;
  try{while($row=$result->fetch()){
   if($kind==='uploads'){unset($row['upload_token']);}
   if($kind==='managed_shares'){
    if($j['action']==='forms_delete_form'&&!in_array('share',OriginContext::current()['verified']['references']['files_operations']??[],true)){throw new BeforeEffect('Original Files-sharing grant is required');}
    try{$native=Server::get(\OCA\Forms\Db\ShareMapper::class)->findById((int)$row['native_share_id']);}catch(\OCP\AppFramework\Db\DoesNotExistException){$native=ManagedShares::historicalShare((int)$j['form']['id'],(int)$row['native_share_id']);}
    if($native->getFormId()!==(int)$j['form']['id']){throw new BeforeEffect('Managed share form changed');}
    $row['approved_tuple']=['owner_id'=>$j['form']['owner_id'],'recipient'=>$native->getShareWith(),'type'=>$native->getShareType()];SharingIdentity::assertType($row['approved_tuple']['type']);
    if(!ManagedShares::isAbsent($row['files_share_id'])){ManagedShares::assertIdentity(SharingIdentity::manager()->getShareById($row['files_share_id'],onlyValid:false),$row['approved_tuple'],$row);}
   }
   $size=strlen(json_encode($row,JSON_THROW_ON_ERROR));if($size>1048576){throw new BeforeEffect('Single recovery row exceeds projection budget');}if($bytes+$size>1048576||microtime(true)-$start>16){break;}
   $bytes+=$size;$id=(int)($row[$kind==='artifacts'?'file_id':'id']);$this->store->put($j,$kind,$id,$row);$j['cursor']=$id;$processed++;
  }}finally{$result->closeCursor();}
  $params[array_key_last($params)]=$j['cursor'];$more=$this->db->executeQuery($sql.' AND '.$pk.' > ? ORDER BY '.$pk.' LIMIT 1',$params)->fetchOne()!==false;
  if(!$more){$j['kind']++;$j['cursor']=0;}
  if($j['kind']===count(self::KINDS)){
   if($j['captured']!==array_sum($j['counts'])-$j['counts']['files']){throw new BeforeEffect('Recovery row counts changed');}
   $j['phase']='file_metadata';$j['kind']=0;$j['cursor']=0;
  }
 }
 private function verify(array &$j,float $start):void {
  $kinds=array_merge(self::KINDS,['files']);$kind=$kinds[$j['kind']];$rows=$this->store->page($j['id'],$kind,$j['cursor']);$processed=0;
  foreach($rows as$r){if(microtime(true)-$start>16){break;}$j['verified_chain']=hash('sha256',$j['verified_chain'].$kind.':'.$r['id'].':'.$r['digest']);$j['verified']++;$j['cursor']=$r['id'];$processed++;}
  if(!$this->store->hasAfter($j['id'],$kind,$j['cursor'])){$j['kind']++;$j['cursor']=0;}
  if($j['kind']===count($kinds)){
   if($j['captured']!==array_sum($j['counts'])||$j['verified']!==$j['captured']||!hash_equals($j['chain'],$j['verified_chain'])){throw new \RuntimeException('Incomplete recovery cannot be sealed');}
   $j['sealed']=true;$j['seal']=BulkStore::digest([$j['id'],$j['actor'],$j['counts'],$j['chain'],$j['form']]);$j['phase']=$j['action']==='forms_transfer'?'copy_files':($j['action']==='forms_delete_form'?'remove_shares':'delete_files');$j['kind']=0;$j['cursor']=0;
  }
 }
 private function applyPage(array &$j,float $start):void {
  if(!$j['sealed']){throw new \RuntimeException('Unsealed recovery');}
  $kinds=$j['action']==='forms_delete_responses'?['answers','submissions']:($j['action']==='forms_delete_form'?['answers','submissions','options','questions','uploads','artifacts','shares','comments','form']:['form']);
  $kind=$kinds[$j['kind']];$rows=$this->store->page($j['id'],$kind,$j['cursor']);$processed=0;
  foreach($rows as$r){if(microtime(true)-$start>16){break;}
   if($j['action']==='forms_transfer'){
    $count=Server::get(BulkFiles::class)->commitTransfer($j,$start,function()use(&$j,$start,$r):int{
     Server::get(BulkFiles::class)->remap($j,$start);Server::get(LinkedMarkers::class)->retire((int)$j['form']['id']);OriginContext::assertFresh();if(microtime(true)-$start>18){throw new BeforeEffect('Transfer mapping deadline exhausted');}
     return $this->db->executeStatement('UPDATE *PREFIX*forms_v2_forms SET owner_id = ?,locked_by = NULL,locked_until = NULL WHERE id = ? AND owner_id = ?',[$j['input']['owner_id'],$r['id'],$j['form']['owner_id']]);
    });
   }else{
    if($kind==='form'){
     $remaining=$this->counts((int)$j['form']['id']);unset($remaining['form'],$remaining['files']);if(array_sum($remaining)!==0){throw new BeforeEffect('Unapproved children prevent final form removal');}
     Server::get(LinkedMarkers::class)->retire((int)$j['form']['id']);
    }
    if($kind==='comments'){$manager=Server::get(\OCP\Comments\ICommentsManager::class);if(!$manager instanceof \OCA\Forms\Mcp\Comments\GuardedCommentsManager){throw new BeforeEffect('Native comments cleanup unavailable');}$count=$manager->deleteApprovedFormComment((int)$j['form']['id'],$r['id']);}else{
    $pk=$kind==='artifacts'?'file_id':'id';
    $count=$this->db->executeStatement('DELETE FROM *PREFIX*'.self::TABLES[$kind].' WHERE '.$pk.' = ?',[$r['id']]);}
   }
   if($count!==1){throw new \RuntimeException('Exact bulk affected count mismatch');}
   $j['done']++;$j['cursor']=$r['id'];$processed++;
  }
  if($processed>0){ResultSourceRevision::touch((int)$j['form']['id']);}
  if(!$this->store->hasAfter($j['id'],$kind,$j['cursor'])){$j['kind']++;$j['cursor']=0;}
  if($j['kind']===count($kinds)){$j['state']=($j['share_events']??false)?'partial':'completed';$j['phase']='complete';}
 }
 private function recovery(string $uid,string $id):array {
  $b=$this->store->load($id);$this->actor($uid,$b);if(($b['backup_format']??0)!==1){throw new BeforeEffect('Unknown bulk recovery format');}
  if(!$b['sealed']||!hash_equals($b['seal']??'',BulkStore::digest([$b['id'],$b['actor'],$b['counts'],$b['chain'],$b['form']]))){throw new BeforeEffect('Recovery is incomplete or corrupt');}
  $live=$this->db->executeQuery('SELECT owner_id FROM *PREFIX*forms_v2_forms WHERE id = ?',[$b['form']['id']])->fetchOne();
  if($live===false){if($b['form']['owner_id']!==$uid){throw new BeforeEffect('Recovery owner unavailable');}}
  else{$f=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$b['form']['id']);$p=(new ScopedServices($uid))->forms()->getPermissions($f);if($f->getOwnerId()!==$uid&&(!in_array('results',$p,true)||!in_array('results_delete',$p,true))){throw new BeforeEffect('Recovery authority revoked');}}
  if(!(new ScopedServices($uid))->get(\OCA\Forms\Service\ConfigService::class)->canCreateForms()){throw new BeforeEffect('Form creation unavailable');}return $b;
 }
 private function insert(string $kind,array $row):int {
  $name=match($kind){'form'=>'Form','questions'=>'Question','options'=>'Option','submissions'=>'Submission','answers'=>'Answer'};$class='OCA\\Forms\\Db\\'.$name;$e=new $class();
  foreach($row as$key=>$v){if($key!=='id'){$e->{'set'.str_replace(' ','',ucwords(str_replace('_',' ',$key)))}($v);}}
  return (int)Server::get($class.'Mapper')->insert($e)->getId();
 }
 private function restorePage(array &$j,float $start):void {
  $backup=$j['input']['backup_id'];$kinds=['form','questions','options','submissions','answers'];$kind=$kinds[$j['kind']];$rows=$this->store->page($backup,$kind,$j['cursor']);$processed=0;
  foreach($rows as$r){if(microtime(true)-$start>16){break;}$row=$r['data'];
   if($kind==='form'){$row['hash']=bin2hex(random_bytes(16));$row['owner_id']=$j['uid'];$row['title'].=' (recovered)';$row['access_enum']=0;$row['state']=1;$row['locked_by']=null;$row['locked_until']=null;$row['file_id']=null;$row['file_format']=null;$row['confirmation_email_enabled']=false;$row['confirmation_email_question_id']=null;}
   if(in_array($kind,['questions','submissions'],true)){$row['form_id']=$j['restored_form_id'];}
   if($kind==='options'){$row['question_id']=$this->store->mapped($j['id'],'questions',(int)$row['question_id']);}
   if($kind==='answers'){
    $oldQ=(int)$row['question_id'];$row['question_id']=$this->store->mapped($j['id'],'questions',$oldQ);$row['submission_id']=$this->store->mapped($j['id'],'submissions',(int)$row['submission_id']);
    if($row['file_id']){$row['file_id']=$this->store->mapped($j['id'],'files',(int)$row['file_id']);}
    $question=$this->store->one($backup,'questions',$oldQ);$row['text']=$this->answerText($j,$question,$row['text']);
   }
   $new=$this->insert($kind,$row);if($kind==='form'){$j['restored_form_id']=$new;}
   $this->store->put($j,$kind,$r['id'],[]);$this->store->map($j['id'],$kind,$r['id'],$new);$j['cursor']=$r['id'];$j['done']++;$processed++;
  }
  if(!$this->store->hasAfter($backup,$kind,$j['cursor'])){$j['kind']++;$j['cursor']=0;if($kind==='form'){$j['phase']='restore_files';}}
  if($j['kind']===count($kinds)){if($j['done']!==array_sum(array_intersect_key($j['counts'],array_flip($kinds)))){throw new \RuntimeException('Complete restored row counts were not verified');}$j['state']=($j['share_events']??false)?'partial':'completed';$j['phase']='complete';}
 }
 private function answerText(array $j,array $q,?string $text):?string {
  if(!in_array($q['type'],['grid','ranking'],true)){return $text;}$value=json_decode($text,true,64,JSON_THROW_ON_ERROR);$map=fn($v)=>$this->store->mapped($j['id'],'options',(int)$v);
  if($q['type']==='ranking'){$value=array_map($map,$value);}else{$new=[];$numeric=(json_decode($q['extra_settings_json']??'{}',true,64,JSON_THROW_ON_ERROR)['questionType']??null)===\OCA\Forms\Constants::ANSWER_GRID_TYPE_NUMBER;foreach($value as$key=>$v){if($numeric){$cells=[];foreach($v as$column=>$number){$cells[$map($column)]=$number;}$new[$map($key)]=$cells;}else{$new[$map($key)]=is_array($v)?array_map($map,$v):$map($v);}}$value=$new;}return json_encode($value,JSON_THROW_ON_ERROR);
 }
 private function dto(array $j):array {
  $total=match($j['action']){'forms_delete_responses'=>$j['counts']['answers']+$j['counts']['submissions']+($j['artifact_deletions']??0),'forms_transfer'=>1,'forms_restore'=>array_sum(array_intersect_key($j['counts'],array_flip(['form','questions','options','submissions','answers']))),default=>array_sum($j['counts'])-$j['counts']['files']};
  return ['bulk_id'=>$j['id'],'operation_key'=>$j['key'],'state'=>$j['state'],'phase'=>$j['phase'],'version'=>$j['version'],'progress'=>['done'=>$j['done'],'total'=>$total,'captured'=>$j['captured'],'capture_total'=>array_sum($j['counts'])],'references'=>['form_id'=>(int)$j['form']['id'],'backup_id'=>$j['sealed']?$j['id']:($j['input']['backup_id']??null),'restored_form_id'=>$j['restored_form_id'],'backup_format'=>'bulk_v1'],'retained_files'=>['recovery'=>$j['retained_files']['recovery']??0,'copies'=>$j['retained_files']['copies']??0,'evidence_id'=>$j['id'],'backup_sealed'=>$j['sealed']],'next'=>$j['state']==='pending','reason'=>$j['reason']??null,'phases'=>['database'=>$j['phase']==='complete'?'committed':($j['done']?'partially_committed':'not_started'),'files'=>$j['counts']['files']===0&&($j['counts']['managed_shares']??0)===0?'not_applicable':($j['phase']==='complete'?'verified':($j['state']==='uncertain'?'unknown':(array_sum($j['retained_files']??[])>0?'retained':'pending'))),'events'=>($j['share_events']??false)?[['kind'=>'files_share','phase'=>$j['event_phase']??'attempted_unverified']]:[]]];
 }
}
