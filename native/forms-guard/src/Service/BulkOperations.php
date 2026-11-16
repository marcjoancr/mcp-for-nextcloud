<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCA\Forms\Mcp\BeforeEffect;
use OCP\{Server,IDBConnection};
/** Server-only bounded destructive protocol. Checkpoints and exact SQL effects commit together. */
final class BulkOperations {
 public const ACTIONS=['forms_delete_responses','forms_delete_form','forms_transfer','forms_restore','forms_delete_question','forms_update','forms_update_share','forms_unshare'];
 private const KINDS=['form','questions','options','submissions','answers','uploads','artifacts','shares','comments','managed_shares','share_recipients'];
 private const TABLES=['form'=>'forms_v2_forms','questions'=>'forms_v2_questions','options'=>'forms_v2_options','submissions'=>'forms_v2_submissions','answers'=>'forms_v2_answers','uploads'=>'forms_v2_uploaded_files','artifacts'=>'forms_mcp_artifacts','shares'=>'forms_v2_shares','comments'=>'comments','managed_shares'=>'forms_mcp_file_shares'];
 public function __construct(private IDBConnection $db,private FormsRevisionFence $fence,private BulkStore $store){}
 private function source(string $kind,int $id,string $action='',array $input=[]):array {
  $query=match($kind){
   'form'=>['SELECT x.* FROM *PREFIX*forms_v2_forms x WHERE x.id = ?',[$id],'x.id'],
   'answers'=>['SELECT x.* FROM *PREFIX*forms_v2_answers x JOIN *PREFIX*forms_v2_submissions s ON s.id=x.submission_id WHERE s.form_id = ?',[$id],'x.id'],
   'options'=>['SELECT x.* FROM *PREFIX*forms_v2_options x JOIN *PREFIX*forms_v2_questions q ON q.id=x.question_id WHERE q.form_id = ?',[$id],'x.id'],
   'managed_shares'=>['SELECT x.*, '.CoreShareIdentity::sql($this->db,'x').' AS id FROM *PREFIX*forms_mcp_file_shares x WHERE x.form_id = ?',[$id],CoreShareIdentity::sql($this->db,'x')],
   'share_recipients'=>["SELECT c.* FROM *PREFIX*share c INNER JOIN *PREFIX*forms_mcp_file_shares m ON c.parent = ".CoreShareIdentity::sql($this->db,'m')." WHERE m.form_id = ? AND ((m.files_share_id LIKE 'ocinternal:%' AND c.share_type = 2) OR (m.files_share_id LIKE 'ocCircleShare:%' AND c.share_type = 7))",[$id],'c.id'],
   'comments'=>["SELECT x.* FROM *PREFIX*comments x WHERE x.object_type = 'forms' AND x.object_id = ?",[(string)$id],'x.id'],
   'artifacts'=>['SELECT x.* FROM *PREFIX*forms_mcp_artifacts x WHERE x.form_id = ?',[$id],'x.file_id'],
   default=>['SELECT x.* FROM *PREFIX*'.self::TABLES[$kind].' x WHERE x.form_id = ?',[$id],'x.id'],
  };return AuthoringScale::source($action,$input,$kind,$query);
 }
 private function counts(int $id,string $action='',array $input=[]):array {$out=[];foreach(self::KINDS as$kind){[$sql,$params]=$this->source($kind,$id,$action,$input);$out[$kind]=(int)$this->db->executeQuery('SELECT COUNT(*) FROM ('.$sql.') bulk_count',$params)->fetchOne();}$out['files']=AuthoringScale::filesRelevant($action,$input)?Server::get(BulkFiles::class)->count($id):0;return $out;}
 private static function key(string $key):void {if(!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$key)){throw new BeforeEffect('Invalid bulk operation key');}}
 private function sourceWriter(string $action):void {
  if($action!=='forms_restore' && !AuthoringScale::metadata($action) && !(Server::get(\OCA\Forms\Mcp\Guard::class)->capabilities()['bulk_source_writer_ready']??false)) {
   throw new BeforeEffect('Source bulk lifecycle requires the activated qualified Forms comments writer');
  }
 }
 private function assertTransferDestinationAbsent(string $uid,string $path):void {
  $manager=Server::get(\OCP\Files\Mount\IMountManager::class);
  if($manager::class!=='OC\\Files\\Mount\\Manager'){throw new BeforeEffect('Transfer destination metadata is unavailable');}
  $mounts=$manager->getAll();if(!is_array($mounts)){throw new BeforeEffect('Transfer destination metadata is unavailable');}
  $home=NativeHomePath::selectedHomeMount($uid,$mounts);$target=(new FreshCore($uid))->user();$setup=$manager->getSetupManager();
  if($setup::class!=='OC\\Files\\SetupManager'){throw new BeforeEffect('Transfer destination metadata is unavailable');}
  if($home===null||!$setup->isSetupComplete($target)){
   $home=StorageAdmission::prospectiveHomePath($uid);
   NativeHomePath::assertNoPathCollision($home,'files/'.$path);
   return;
  }
  $root=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
  if($root->nodeExists($path)){throw new BeforeEffect('Transfer destination already exists');}
 }
 private function admit(string $uid,string $action,array $input,callable $authorize,bool $metadataPreview=false):void {
  if(!BulkStore::ready()||!in_array($action,self::ACTIONS,true)){throw new BeforeEffect('Native bounded lifecycle unavailable');}
  OriginContext::assertFresh();if(strlen(json_encode($input,JSON_THROW_ON_ERROR))>1048576){throw new BeforeEffect('Bulk input exceeds budget');}
  if($action==='forms_restore'){Server::get(\OCA\Forms\Mcp\Guard::class)->assertPrincipal($uid);if(Server::get(\OCP\IUserSession::class)->getUser()?->getUID()!==$uid){throw new BeforeEffect('Restore actor unavailable');}$backup=$this->recovery($uid,(string)$input['backup_id'],$input['backup_format']??1);if($backup['counts']['files']>($backup['historical_unavailable_files']??0)){DestinationFiles::admitProspective($uid,\OCA\Forms\Constants::FILES_FOLDER);}return;}
  $this->sourceWriter($action);$authorize();AuthoringScale::admit($action,$input);$form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$input['id']);
  if($action==='forms_delete_form'&&(int)$this->db->executeQuery("SELECT COUNT(*) FROM *PREFIX*comments WHERE object_type = 'forms' AND object_id = ?",[(string)$input['id']])->fetchOne()>0&&!Server::get(\OCA\Forms\Mcp\Guard::class)->capabilities()['comments']){throw new BeforeEffect('Whole-form comment cleanup requires the activated native comments guard');}
  if($form->getState()===2){throw new BeforeEffect('Archived form is unavailable for bulk mutation');}
  if(in_array($action,['forms_delete_form','forms_transfer'],true)&&$form->getOwnerId()!==$uid){throw new BeforeEffect('Only the current form owner may perform this operation');}
  if($action==='forms_transfer'&&(int)$this->db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ?',[$input['id']])->fetchOne()>0&&!$metadataPreview&&!in_array('share',OriginContext::current()['verified']['references']['files_operations']??[],true)){throw new BeforeEffect('Original Files-sharing grant is required');}
  if($action==='forms_transfer'&&(int)$this->db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ?',[$input['id']])->fetchOne()>0){TransferShareDispatcher::inventory(Server::get(\OCP\EventDispatcher\IEventDispatcher::class));}
  if($action==='forms_transfer'){(new FreshCore($input['owner_id']??''))->assertPrincipal(false,false);$target=Server::get(\OCP\IUserManager::class)->get($input['owner_id']??'');if(!$target||!$target->isEnabled()){throw new BeforeEffect('Transfer target unavailable');}}
 }
 private function previewBinding(string $action,array $input,string $files):string {return in_array($action,AuthoringScale::ACTIONS,true)?BulkStore::digest([$files,array_diff_key($input,['_mcp_origin'=>true]),AuthoringScale::previewManifest($action,$input)]):($action==='forms_transfer'?BulkStore::digest([$files,\OCA\Forms\Mcp\Comments\CommentsRuntime::principal($input['owner_id']),Server::get(\OCA\Mcp\Mcp\Operations\ActorIdentity::class)->capture($input['owner_id']),NativeHistoryIdentity::ready()?NativeHistoryIdentity::capture($input['owner_id']):null]):$files);}
 public function snapshot(string $uid,string $action,array $input,callable $authorize,bool $metadataPreview=false):array {
  $rev=$this->fence->revision();$this->admit($uid,$action,$input,$authorize,$metadataPreview);if($action==='forms_transfer'){Server::get(\OCA\Mcp\Mcp\Operations\ActorIdentity::class)->capture($input['owner_id']);if(NativeHistoryIdentity::ready()){NativeHistoryIdentity::capture($input['owner_id']);}}
  if($action==='forms_restore'){$backup=$this->recovery($uid,$input['backup_id'],$input['backup_format']??1);$counts=$backup['counts'];$form=$backup['form'];}
  else{$counts=$this->counts((int)$input['id'],$action,$input);$form=$this->db->executeQuery('SELECT id,owner_id,title FROM *PREFIX*forms_v2_forms WHERE id = ?',[$input['id']])->fetch();}
  $historyCount=$action==='forms_restore'?($backup['historical_unavailable_files']??0):(AuthoringScale::filesRelevant($action,$input)?count(Server::get(BulkFiles::class)->history((int)$input['id'])):0);
  if(array_sum($counts)>BulkStore::MAX_ROWS||$counts['files']>1000){throw new BeforeEffect('Bulk recovery row quota exceeded');}
  if($action==='forms_transfer'&&($counts['files']>$historyCount||$counts['managed_shares']>0)){DestinationFiles::admitProspective($input['owner_id'],\OCA\Forms\Constants::FILES_FOLDER);}
  if($action==='forms_transfer'&&($counts['files']>0||$counts['managed_shares']>0)){$native=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$input['id']);$path=Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($native);$this->assertTransferDestinationAbsent($input['owner_id'],$path);}
  if($rev!==$this->fence->revision()){throw new BeforeEffect('Forms changed during bulk preview');}
  return ['revision'=>'b1:'.$rev.':'.$this->previewBinding($action,$input,$action==='forms_restore'?str_repeat('0',64):AuthoringScale::fingerprint($action,$input,(int)$form['id'])),'action'=>$action,'form'=>['id'=>(int)$form['id'],'title'=>$form['title'],'ownerId'=>$form['owner_id']], 'counts'=>$counts,'file_manifest'=>in_array($action,AuthoringScale::ACTIONS,true)?AuthoringScale::previewManifest($action,$input):['files_share'=>($counts['managed_shares']??0)>0],'historical_unavailable_files'=>$historyCount,'source_epoch'=>BulkEpoch::epoch((int)$form['id']),'target'=>$input['owner_id']??null,'destination'=>$this->destination($uid,$action,$input),'preview_collections'=>['files','managed_shares'],'authoring'=>AuthoringScale::projection($action,$input),'recovery'=>AuthoringScale::metadata($action)?'non_restorable_effect_evidence':($historyCount>0?'sealed_current_state_with_unavailable_history':'complete_sealed_before_effect'),'files'=>'exact_managed_ids_only','folders'=>'retained','linked_outputs'=>'retained'];
 }
 /** Read-only inspection of every approved external effect, bound to the preview revision. */
 public function previewPage(string $uid,string $action,array $input,string $revision,string $kind,int $cursor,int $limit,callable $authorize):array {
  if(!in_array($kind,['files','managed_shares'],true)||$cursor<0||$limit<1||$limit>100){throw new BeforeEffect('Invalid bounded preview page');}
  $snapshot=$this->snapshot($uid,$action,$input,$authorize);if(!hash_equals($revision,$snapshot['revision'])){throw new BeforeEffect('Bulk preview stale');}
  $backup=$action==='forms_restore'?$this->recovery($uid,$input['backup_id'],$input['backup_format']??1):null;$items=[];$bytes=0;$after=$cursor;
  if($kind==='files'){
   $rows=$backup?$this->store->page($backup['id'],'files',$cursor):(AuthoringScale::filesRelevant($action,$input)?Server::get(BulkFiles::class)->previewFiles((int)$input['id'],$cursor,$limit):[]);
   foreach(array_slice($rows,0,$limit)as$row){
    BulkDeadline::check();$m=$backup?$row['data']:$row;$id=(int)($backup?$row['id']:$m['file_id']);
    if(HistoricalFiles::isUnavailable($m)){$item=['file_id'=>$id,'path'=>null,'name'=>null,'size'=>null,'etag'=>null,'owner'=>$snapshot['form']['ownerId'],'action'=>'unavailable_history','destination'=>null,'recovery'=>'historical_bytes_unavailable','availability'=>'historical_unavailable','unavailable_cause'=>'unverified'];$size=strlen(json_encode($item,JSON_THROW_ON_ERROR));if($bytes+$size>1040000){break;}$bytes+=$size;$items[]=$item;$after=$id;continue;}
    $effect=in_array($action,['forms_transfer','forms_restore'],true)?'copy':(Server::get(BulkFiles::class)->deletes($action,(int)$input['id'],$id,$input)?'delete':'retain');
    $item=['file_id'=>$id,'path'=>$m['path'],'name'=>$m['name'],'size'=>(int)$m['size'],'etag'=>$m['etag'],'owner'=>$snapshot['form']['ownerId'],'action'=>$effect,'destination'=>$effect==='copy'?$snapshot['destination']:null,'recovery'=>AuthoringScale::metadata($action)?'not_applicable':'retained_private_copy_before_source_effect'];
    $size=strlen(json_encode($item,JSON_THROW_ON_ERROR));if($size>1040000){throw new BeforeEffect('Preview item exceeds byte budget');}if($bytes+$size>1040000){break;}$bytes+=$size;$items[]=$item;$after=$id;
   }
  }else{
   if($backup){$rows=$this->store->page($backup['id'],'managed_shares',$cursor);}else{[$sql,$params,$pk]=$this->source('managed_shares',(int)$input['id'],$action,$input);$rows=$this->db->executeQuery($sql.' AND '.$pk.' > ? ORDER BY '.$pk.' LIMIT '.$limit,[...$params,$cursor])->fetchAll();}
   foreach(array_slice($rows,0,$limit)as$row){
    BulkDeadline::check();$r=$backup?$row['data']:$row;$id=(int)($backup?$row['id']:$r['id']);
    if($backup){$tuple=$r['approved_tuple'];}else{try{$share=Server::get(\OCA\Forms\Db\ShareMapper::class)->findById((int)$r['native_share_id']);}catch(\OCP\AppFramework\Db\DoesNotExistException){$share=ManagedShares::historicalShare((int)$input['id'],(int)$r['native_share_id']);}$tuple=['owner_id'=>$snapshot['form']['ownerId'],'recipient'=>$share->getShareWith(),'type'=>$share->getShareType()];if(!ManagedShares::isAbsent($r['files_share_id'])){ManagedShares::assertIdentity(SharingIdentity::manager()->getShareById($r['files_share_id'],onlyValid:false),$tuple,$r);}}
    $item=['native_share_id'=>(int)$r['native_share_id'],'recipient'=>$tuple['recipient'],'type'=>$tuple['type'],'folder_ids'=>[(int)$r['folder_id']],'managed_share_ids'=>[$r['files_share_id']],'action'=>in_array($action,['forms_delete_form','forms_unshare'],true)||($action==='forms_update_share'&&isset($input['permissions'])&&!in_array('results',$input['permissions'],true))?'remove':($backup?'not_recreated':($action==='forms_transfer'?($tuple['type']===0&&$tuple['recipient']===$input['owner_id']?'target_ownership':'stage_target_share'):'retain')),'destination_owner'=>$action==='forms_transfer'?$input['owner_id']:null,'destination'=>$action==='forms_transfer'?$snapshot['destination']:null,'source_action'=>in_array($action,['forms_unshare'],true)||($action==='forms_update_share'&&isset($input['permissions'])&&!in_array('results',$input['permissions'],true))?'remove':'retain','permissions'=>['read'],'recipient_acceptance'=>'preserved','recipient_state_count'=>$backup?null:(int)$this->db->executeQuery('SELECT COUNT(*) FROM *PREFIX*share WHERE parent = ? AND share_type = ?',[$id,$tuple['type']===7?7:2])->fetchOne()];
    if($action==='forms_transfer'&&!ManagedShares::isAbsent($r['files_share_id'])){$folder=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($snapshot['form']['ownerId'])->getById((int)$r['folder_id'])[0]??null;if(!$folder instanceof \OCP\Files\Folder){throw new BeforeEffect('Preview share folder unavailable');}$item['source_folder_path']=$folder->getPath();$item['destination_folder_template']=$snapshot['destination']['base'].'/bulk-{operation_id}/source/'.BulkTransferShares::relative($snapshot['form']['ownerId'],$folder->getPath());}
    $size=strlen(json_encode($item,JSON_THROW_ON_ERROR));if($size>1040000){throw new BeforeEffect('Preview item exceeds byte budget');}if($bytes+$size>1040000){break;}$bytes+=$size;$items[]=$item;$after=$id;
   }
  }
  if($backup){$more=$this->store->hasAfter($backup['id'],$kind,$after);}elseif($kind==='files'){$more=AuthoringScale::filesRelevant($action,$input)&&Server::get(BulkFiles::class)->previewFiles((int)$input['id'],$after,1)!==[];}else{[$sql,$params,$pk]=$this->source($kind,(int)$input['id'],$action,$input);$more=$this->db->executeQuery($sql.' AND '.$pk.' > ? ORDER BY '.$pk.' LIMIT 1',[...$params,$after])->fetchOne()!==false;}
  $this->admit($uid,$action,$input,$authorize);$current='b1:'.$this->fence->revision().':'.$this->previewBinding($action,$input,$backup?str_repeat('0',64):AuthoringScale::fingerprint($action,$input,(int)$input['id']));BulkDeadline::check();if(!hash_equals($revision,$current)){throw new BeforeEffect('Bulk preview changed during inspection');}
  return ['items'=>$items,'total'=>(int)$snapshot['counts'][$kind],'next_cursor'=>$more?$after:null,'complete'=>!$more,'revision'=>$revision];
 }
 private function destination(string $uid,string $action,array $input):?array {
  if(!in_array($action,['forms_transfer','forms_restore'],true)){return null;}
  if($action==='forms_transfer'){$form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$input['id']);$base=Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);}
  else{$base=\OCA\Forms\Constants::FILES_FOLDER.'/{new_form_id} - {normalized_recovered_title}';}
  return ['owner'=>$input['owner_id']??$uid,'base'=>$base,'path_template'=>$action==='forms_transfer'?$base.'/bulk-{operation_id}/source/{original_owner_relative_path}':$base.'/bulk-{operation_id}/{source_file_id}-{source_name}','overwrite'=>false,'form'=>$action==='forms_restore'?'new_private_closed':'same_form'];
 }
 public function start(string $uid,string $action,array $input,string $revision,string $key,callable $authorize):array {
  self::key($key);if(!preg_match('/^b1:([0-9]+):([a-f0-9]{64})$/D',$revision,$parts)){throw new BeforeEffect('Bulk preview identity invalid');}$fileRevision=$parts[2];$revision=$parts[1];$intent=$input;unset($intent['_mcp_origin']);$fingerprint=BulkStore::digest([$action,$intent,OriginContext::identity($uid)]);
  return $this->fence->withLock(function()use($uid,$action,$input,$revision,$key,$authorize,$fingerprint,$fileRevision):array{
   $old=$this->store->byKey($key);if($old){$this->actor($uid,$old);if(!hash_equals($fingerprint,$old['fingerprint'])){throw new BeforeEffect('Bulk key conflict');}return $this->dto($old);}
   return $this->fence->transaction(function()use($uid,$action,$input,$revision,$key,$authorize,$fingerprint,$fileRevision):array{
   if((string)((int)$this->fence->revision()-1)!==$revision){throw new BeforeEffect('Bulk preview stale');}
   $this->admit($uid,$action,$input,$authorize);$targetPrincipal=$action==='forms_transfer'?\OCA\Forms\Mcp\Comments\CommentsRuntime::principal($input['owner_id']):null;if($action!=='forms_restore'){$actualFiles=AuthoringScale::fingerprint($action,$input,(int)$input['id']);if(!hash_equals($fileRevision,$this->previewBinding($action,$input,$actualFiles))){throw new BeforeEffect('Managed Files or transfer target changed after preview');}$fileRevision=$actualFiles;}$backup=$action==='forms_restore'?$this->recovery($uid,$input['backup_id'],$input['backup_format']??1):null;
   $counts=$backup['counts']??$this->counts((int)$input['id'],$action,$input);if(array_sum($counts)>BulkStore::MAX_ROWS||$counts['files']>1000){throw new BeforeEffect('Bulk recovery row quota exceeded');}
   $form=$backup['form']??$this->db->executeQuery('SELECT * FROM *PREFIX*forms_v2_forms WHERE id = ?',[$input['id']])->fetch();
   $historyCount=$backup!==null?($backup['historical_unavailable_files']??0):(AuthoringScale::filesRelevant($action,$input)?count(Server::get(BulkFiles::class)->history((int)$form['id'])):0);
   if($action==='forms_transfer'&&($counts['files']>$historyCount||$counts['managed_shares']>0)){DestinationFiles::admitProspective($input['owner_id'],\OCA\Forms\Constants::FILES_FOLDER);}
   $j=['id'=>hash('sha256','forms-bulk:'.$key),'key'=>$key,'actor'=>OriginContext::identity($uid),'uid'=>$uid,'action'=>$action,'input'=>$input,'fingerprint'=>$fingerprint,'origin'=>OriginContext::current()['origin'],'revision'=>$this->fence->revision(),'backup_format'=>AuthoringScale::metadata($action)?'authoring_effects_v1':($backup['backup_format']??($historyCount>0?2:1)),'historical_unavailable_files'=>$historyCount,'authoring_manifest'=>in_array($action,AuthoringScale::ACTIONS,true)?AuthoringScale::previewManifest($action,$input):[],'file_revision'=>$fileRevision,'epoch'=>$backup?0:BulkEpoch::epoch((int)$form['id']),'global_epoch'=>BulkEpoch::epoch(0),'native_manifest'=>BulkEpoch::runtime(),'target_principal'=>$targetPrincipal,'target_actor'=>$action==='forms_transfer'?Server::get(\OCA\Mcp\Mcp\Operations\ActorIdentity::class)->capture($input['owner_id']):null,'target_native_actor'=>$action==='forms_transfer'&&NativeHistoryIdentity::ready()?NativeHistoryIdentity::capture($input['owner_id']):null,'question_effect_count'=>$action==='forms_delete_question'?1:null,'form'=>$form,'counts'=>$counts,'state'=>'pending','phase'=>$backup?'restore':'capture','kind'=>0,'cursor'=>0,'version'=>1,'captured'=>0,'done'=>0,'bytes'=>0,'chain'=>'','verified_chain'=>'','verified'=>0,'sealed'=>false,'restored_form_id'=>null];
   if(AuthoringScale::metadata($action)){AuthoringScale::assertOriginalFiles($j['authoring_manifest']);}$this->store->create($j);BulkDeadline::check();return $this->dto($j);
   });
  });
 }
 private function sourceEpoch(array $j):int {return $j['action']==='forms_restore'?($j['restored_form_id']===null?0:BulkEpoch::epoch((int)$j['restored_form_id'])):BulkEpoch::epoch((int)$j['form']['id']);}
 private function sourceMatches(array $j):bool {return hash_equals($j['native_manifest']??'',BulkEpoch::runtime())&&($j['global_epoch']??-1)===BulkEpoch::epoch(0)&&$j['epoch']===$this->sourceEpoch($j);}
 private function actor(string $uid,array $j):void {if(!hash_equals($j['actor'],OriginContext::identity($uid))||$uid!==$j['uid']){throw new BeforeEffect('Bulk operation unavailable to this actor');}}
 public function lookup(string $uid,string $key):?array {$j=$this->store->byKey($key);if(!$j){return null;}$this->actor($uid,$j);return OriginContext::within($uid,$j['origin'],'lookup',$j['input'],fn()=> $this->dto($j));}
 /** Read-only serialized evidence. Absence is proven only at the exact untouched approved version. */
 public function stepEvidence(string $uid,string $id,string $key,int $approvedVersion):?array {
  self::key($key);if($approvedVersion<1){throw new BeforeEffect('Invalid approved step version');}
  $j=$this->store->load($id);$this->actor($uid,$j);
  return OriginContext::within($uid,$j['origin'],'lookup',$j['input'],fn()=> $this->fence->withLock(function()use($uid,$id,$key,$approvedVersion):?array{
   OriginContext::assertFresh();$j=$this->store->load($id);$this->actor($uid,$j);$receipt=$this->store->receipt($id,$key);
   if($receipt!==null){if($receipt['version']!==$approvedVersion+1){throw new BeforeEffect('Step receipt version does not match');}return BulkStepEvidence::resolve($receipt,$j,static fn(string $operation)=>Server::get(ManagedSharePhase::class)->phase($operation));}
   if($j['state']==='uncertain'&&($j['claim']['step_key']??null)===$key&&$j['version']===$approvedVersion+1){return $this->dto($j);}
   if($j['state']==='uncertain'&&($j['native_home_claim']['home_phase']['step_key']??null)===$key&&$j['version']===$approvedVersion+1){return $this->dto($j);}
   if($j['state']==='pending'&&$j['version']===$approvedVersion&&!isset($j['claim'])&&hash_equals($j['native_manifest']??'',BulkEpoch::runtime())){return null;}
   throw new BeforeEffect('Step evidence is unavailable; absence does not prove no effect');
  }));
 }
 public function status(string $uid,string $id):array {$j=$this->store->load($id);$this->actor($uid,$j);return OriginContext::within($uid,$j['origin'],'lookup',$j['input'],fn()=> $this->dto($j));}
 public function step(string $uid,string $id,int $version,string $key,callable $authorize,?callable $files=null):array {
  self::key($key);$j=$this->store->load($id);$this->actor($uid,$j);
  return OriginContext::within($uid,$j['origin'],$j['action'],$j['input'],function()use($uid,$id,$version,$key,$authorize,$files):array {
   if(!NativeHomeChild::claimBulkStep($uid,$id,$version,$key)){$homeStep=$this->nativeHomeStep($uid,$id,$version,$key,$authorize);if($homeStep!==null){return $homeStep;}}
   return $this->fence->withLock(function()use($uid,$id,$version,$key,$authorize,$files):array {
   $j=$this->store->load($id);$this->actor($uid,$j);$this->sourceWriter($j['action']);$prior=$this->store->receipt($id,$key);if($prior!==null){return BulkStepEvidence::resolve($prior,$j,static fn(string $operation)=>Server::get(ManagedSharePhase::class)->phase($operation));}
   if($j['version']!==$version){throw new BeforeEffect('Bulk version conflict');}
   if(!in_array($j['state'],['pending'],true)){return $this->dto($j);}
   if(($j['historical_unavailable_files']??0)>0){HistoricalFiles::requireReady();}
   if(in_array($j['phase'],['backup_files','copy_files','delete_files','restore_files','remove_shares','stage_shares','stage_recipients'],true)){
    return $this->fileStep($j,$uid,$key,$authorize,$files);
   }
   $dto=$this->fence->transaction(function()use(&$j,$uid,$key,$authorize,$files):array {
    $this->sourceWriter($j['action']);if(!$this->sourceMatches($j)){$j['state']=($j['done']>0||($j['source_effects']??false)||(array_sum($j['retained_files']??[])+($j['retained_shares']??0)+($j['retained_folders']??0))>0)?'partial':'failed_before_effect';$j['reason']='source_changed';}
    else {
     $this->admit($uid,$j['action'],$j['input'],$authorize);if(!$j['sealed']&&$j['action']!=='forms_restore'&&!hash_equals($j['file_revision'],AuthoringScale::fingerprint($j['action'],$j['input'],(int)$j['form']['id']))){throw new BeforeEffect('Managed Files changed before recovery seal');}
     $started=BulkDeadline::started();BulkDeadline::check();
     match($j['phase']){
      'capture'=>$this->capture($j,$started),
      'verify'=>$this->verify($j,$started),
      'file_metadata'=>OriginContext::withFileBoundary($files,function()use(&$j,$started){$n=0;do{Server::get(BulkFiles::class)->metadata($j);$n++;}while(AuthoringScale::metadata($j['action'])&&$j['phase']==='file_metadata'&&$n<100&&microtime(true)-$started<12);}),
      'apply'=>OriginContext::withFileBoundary($files,function()use(&$j,$started,$authorize){$this->applyPage($j,$started,$authorize);}),
      'restore'=>$this->restorePage($j,$started),
      default=>throw new \RuntimeException('Unknown bulk phase'),
     };
    }
    BulkDeadline::check();$j['version']++;$j['revision']=$this->fence->revision();$j['epoch']=$this->sourceEpoch($j);$this->store->save($j);$dto=$this->dto($j);$this->store->record($j['id'],$key,$dto);BulkDeadline::check();return $dto;
   });
   return BulkStepEvidence::resolve($dto,$j,static fn(string $operation)=>Server::get(ManagedSharePhase::class)->phase($operation));
  });
  });
 }
 /** A fresh child owns target setup and the complete ordinary Forms step that uses it. */
 private function nativeHomeStep(string $uid,string $id,int $version,string $key,callable $authorize):?array {
  $job=$this->store->load($id);$this->actor($uid,$job);
  if($job['state']!=='pending'||$job['version']!==$version||($job['action']!=='forms_restore'&&!$job['sealed'])){return null;}
  $phase=$job['phase'];$target=match($job['action']){'forms_transfer'=>$job['input']['owner_id']??null,'forms_restore'=>$job['uid'],default=>null};
  $files=(int)($job['counts']['files']??0)-(int)($job['historical_unavailable_files']??0);
  $hasTargetEffects=$job['action']==='forms_transfer'
   ?($files>0||(int)($job['counts']['managed_shares']??0)>0)
   :($job['action']==='forms_restore'&&$files>0);
  $targetPhases=$job['action']==='forms_transfer'?['copy_files','stage_shares','stage_recipients','apply']:['restore_files'];
  if(!$hasTargetEffects||!is_string($target)||!in_array($phase,$targetPhases,true)){return null;}
  if(isset($job['native_home_claim'])){throw new BeforeEffect('Native Home step requires explicit recovery');}
  $manager=Server::get(\OCP\Files\Mount\IMountManager::class);
  if($manager::class!=='OC\\Files\\Mount\\Manager'){throw new BeforeEffect('Native Home mount metadata is unavailable');}
  $mounts=$manager->getAll();if(!is_array($mounts)){throw new BeforeEffect('Native Home mount metadata is unavailable');}
  $home=NativeHomePath::selectedHomeMount($target,$mounts);$targetUser=(new FreshCore($target))->user();$setup=$manager->getSetupManager();
  if($setup::class!=='OC\\Files\\SetupManager'){throw new BeforeEffect('Native Home setup metadata is unavailable');}
  if($home!==null&&$setup->isSetupComplete($targetUser)){return null;}

  $journalFile=NativeHomeProcess::createJournalFile();$path=$journalFile['path'];$stream=$journalFile['stream'];
  $homeClaim=['home_phase'=>['nonce'=>bin2hex(random_bytes(32)),'phase'=>$phase,'uid'=>$uid,'action'=>$job['action'],
   'target_uid'=>$target,'step_key'=>$key,'version'=>$version]];
  $claimed=false;$envelope=null;
  try {
   $job=$this->fence->transaction(function()use($uid,$id,$version,$key,$authorize,$homeClaim,$path):array{
    OriginContext::assertFresh();$current=$this->store->load($id);$this->actor($uid,$current);
    if($current['state']!=='pending'||$current['version']!==$version||$current['phase']!==$homeClaim['home_phase']['phase']
      ||isset($current['native_home_claim'])||($current['action']!=='forms_restore'&&!$current['sealed'])){throw new BeforeEffect('Native Home claim changed');}
    $this->admit($uid,$current['action'],$current['input'],$authorize);
    $current['native_home_claim']=$homeClaim;$current['native_home_journal']=basename($path);$this->store->save($current);return$current;
   });
   $claimed=true;
   $envelope=OriginContext::nativeHomeEnvelope($job,$homeClaim);
   $root=\OC::$SERVERROOT;$script=dirname(__DIR__).'/native-home-worker.php';
   $receipt=NativeHomeProcess::run($envelope,$script,$root,$stream);
   $dto=$receipt['dto']??null;
   if(!is_array($dto)||($dto['bulk_id']??null)!==$id||($dto['version']??null)!==$version+1){throw new \RuntimeException('Native Home bulk step receipt differs');}
   if(in_array($dto['state']??null,['pending','completed','partial','cancelled','failed_before_effect'],true)){@unlink($path);}
   return $dto;
  } catch(\Throwable $error) {
   if(!$claimed){@unlink($path);}
   $childFailure=$claimed&&is_array($envelope)?NativeHomeProcess::takeFailure($envelope):null;
   $journalEvidence=null;
   if($claimed&&is_array($envelope)){try{$journalEvidence=NativeHomeJournal::read($stream,$envelope);}catch(\Throwable){}}
   try{$this->fence->transaction(function()use($uid,$id,$version,$key,$journalEvidence,$childFailure):void{
    $current=$this->store->load($id);$receipt=$this->store->receipt($id,$key);
    if($receipt!==null||!isset($current['native_home_claim'])||($current['native_home_claim']['home_phase']['step_key']??null)!==$key){return;}
    if(is_array($childFailure)){$current['native_home_failure']=$childFailure;}
    if(is_array($journalEvidence)){$this->store->putMapping($current,'native_home',$version,$journalEvidence);$current['native_home_receipt']=['phase'=>$current['native_home_claim']['home_phase']['phase'],'version'=>$version,
      'state'=>$journalEvidence['state'],'effects'=>$journalEvidence['effects'],'pending'=>count($journalEvidence['pending']),'failed'=>$journalEvidence['failed'],'chain'=>$journalEvidence['chain']];}
    if(($current['version']??null)===$version){$current['state']='uncertain';$current['reason']='native_home_recovery_required';$current['version']=$version+1;$this->store->save($current);}
   });}catch(\Throwable){}
   $current=$this->store->load($id);$receipt=$this->store->receipt($id,$key);
   if($receipt!==null){return BulkStepEvidence::resolve($receipt,$current,static fn(string $operation)=>Server::get(ManagedSharePhase::class)->phase($operation));}
   if(($current['state']??null)==='uncertain'&&($current['version']??null)===$version+1){return $this->dto($current);}
   throw new BeforeEffect('Native Home initialization failed; recovery evidence was retained',0,$error);
  } finally {if(is_resource($stream)){fclose($stream);}}
 }
 private function fileStep(array $j,string $uid,string $key,callable $authorize,?callable $files):array {
  if(!$this->sourceMatches($j)){return $this->fence->transaction(function()use(&$j,$key):array{$j['state']=($j['done']>0||($j['source_effects']??false)||(array_sum($j['retained_files']??[])+($j['retained_shares']??0)+($j['retained_folders']??0))>0)?'partial':'failed_before_effect';$j['reason']='source_changed';$j['version']++;$this->store->save($j);$dto=$this->dto($j);$this->store->record($j['id'],$key,$dto);return $dto;});}
  $worker=Server::get(BulkFiles::class);$row=$worker->next($j);
  $this->fence->transaction(function()use(&$j,$uid,$authorize,$worker,$row,$key):void{
   if(!$this->sourceMatches($j)){throw new BeforeEffect('Bulk source changed');}
   $this->admit($uid,$j['action'],$j['input'],$authorize);if($j['phase']==='backup_files'&&!hash_equals($j['file_revision'],AuthoringScale::fingerprint($j['action'],$j['input'],(int)$j['form']['id']))){throw new BeforeEffect('Managed Files changed before recovery seal');}
   if($row===null){
    $j['phase']=match($j['phase']){'backup_files'=>'verify','restore_files'=>'restore','remove_shares'=>'delete_files','copy_files'=>'stage_shares','stage_shares'=>'stage_recipients',default=>'apply'};$j['cursor']=0;
   }else{
    BulkDeadline::check();
    // Reservation survives an unknown external write, including a lost acknowledgement.
    $this->store->reserve($j,$worker->reservation($j,$row));$j['state']='uncertain';if(in_array($j['phase'],['remove_shares','stage_shares'],true)){$j['share_events']=true;$j['event_phase']='uncertain';}$j['claim']=['step_key'=>$key,'file_id'=>$row['id'],'phase'=>$j['phase']];
   }
   $j['version']++;$j['revision']=$this->fence->revision();$j['epoch']=$this->sourceEpoch($j);$this->store->save($j);if($row===null){$this->store->record($j['id'],$key,$this->dto($j));}BulkDeadline::check();
  });
  if($row===null){return $this->dto($j);}
  try {
   return $this->fence->transaction(function()use(&$j,$uid,$key,$authorize,$worker,$row,$files):array{
    $this->admit($uid,$j['action'],$j['input'],$authorize);$beforeEpoch=$this->sourceEpoch($j);$globalEpoch=BulkEpoch::epoch(0);$worker->effect($j,$row,$files);BulkDeadline::check();if(!HistoricalFiles::isUnavailable($row['data'])&&in_array($j['phase'],['backup_files','copy_files','restore_files'],true)){$kind=$j['phase']==='backup_files'?'recovery':'copies';$j['retained_files'][$kind]=($j['retained_files'][$kind]??0)+1;}if($beforeEpoch!==$this->sourceEpoch($j)||$globalEpoch!==BulkEpoch::epoch(0)){throw new \RuntimeException('Native source changed during Files callbacks');}if(in_array($j['phase'],['delete_files','remove_shares','restore_files'],true)){BulkEpoch::touch((int)($j['action']==='forms_restore'?$j['restored_form_id']:$j['form']['id']));}OriginContext::assertFresh();if($j['phase']==='delete_files'&&$row['data']['delete']){$j['source_effects']=true;$j['files_done']=($j['files_done']??0)+1;}
    $j['cursor']=$row['id'];$j['state']='pending';unset($j['claim']);$j['revision']=$this->fence->revision();$j['epoch']=$this->sourceEpoch($j);$this->store->save($j);$dto=$this->dto($j);$this->store->record($j['id'],$key,$dto);BulkDeadline::check();return $dto;
   });
  }catch(\Throwable){return $this->dto($this->store->load($j['id']));}
 }
 public function cancel(string $uid,string $id,int $version,string $key):array {
  self::key($key);$original=$this->store->load($id);$this->actor($uid,$original);return OriginContext::within($uid,$original['origin'],'lookup',$original['input'],fn()=> $this->fence->transaction(function()use($uid,$id,$version,$key):array{$j=$this->store->load($id);$this->actor($uid,$j);$prior=$this->store->receipt($id,$key);if($prior){return $prior;}if($version!==$j['version']){throw new BeforeEffect('Bulk version conflict');}if($j['state']==='pending'){$j['state']=($j['done']>0||($j['source_effects']??false)||(array_sum($j['retained_files']??[])+($j['retained_shares']??0)+($j['retained_folders']??0))>0)?'partial':'cancelled';$j['version']++;$this->store->save($j);}$dto=$this->dto($j);$this->store->record($id,$key,$dto);return $dto;}));
 }
 // Yield ordinary row pages early enough to persist their cursor before the hard18s deadline.
 private function capture(array &$j,float $start):void {
  if(AuthoringScale::metadata($j['action'])){while($j['kind']<count(self::KINDS)&&$j['counts'][self::KINDS[$j['kind']]]===0){$j['kind']++;$j['cursor']=0;}if($j['kind']===count(self::KINDS)){if($j['captured']!==array_sum($j['counts'])-$j['counts']['files']){throw new BeforeEffect('Authoring inventory count changed');}$j['phase']='file_metadata';$j['kind']=0;$j['cursor']=0;return;}}
  $kind=self::KINDS[$j['kind']];[$sql,$params,$pk]=$this->source($kind,(int)$j['form']['id'],$j['action'],$j['input']);$params[]=$j['cursor'];$result=$this->db->executeQuery($sql.' AND '.$pk.' > ? ORDER BY '.$pk.' LIMIT 100',$params);$bytes=0;$processed=0;
  try{while($row=$result->fetch()){
   if($kind==='answers'){$row=HistoricalFiles::decorateAnswer($row,(int)$j['form']['id']);}
   if($kind==='uploads'){unset($row['upload_token']);}
   if($kind==='comments'){$row['historical_author']=\OCA\Forms\Mcp\Comments\HistoricalAuthor::capture($row);}
   if($kind==='managed_shares'){
    if(in_array($j['action'],['forms_delete_form','forms_transfer'],true)&&!in_array('share',OriginContext::current()['verified']['references']['files_operations']??[],true)){throw new BeforeEffect('Original Files-sharing grant is required');}
    try{$native=Server::get(\OCA\Forms\Db\ShareMapper::class)->findById((int)$row['native_share_id']);}catch(\OCP\AppFramework\Db\DoesNotExistException){$native=ManagedShares::historicalShare((int)$j['form']['id'],(int)$row['native_share_id']);}
    if($native->getFormId()!==(int)$j['form']['id']){throw new BeforeEffect('Managed share form changed');}
    $row['approved_tuple']=['owner_id'=>$j['form']['owner_id'],'recipient'=>$native->getShareWith(),'type'=>$native->getShareType()];SharingIdentity::assertType($row['approved_tuple']['type']);
    if(!ManagedShares::isAbsent($row['files_share_id'])){ManagedShares::assertIdentity(SharingIdentity::manager()->getShareById($row['files_share_id'],onlyValid:false),$row['approved_tuple'],$row);}
   }
   if($kind==='managed_shares'&&$j['action']==='forms_transfer'){$row=BulkTransferShares::capture($row,$j);}
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
  $kinds=array_merge(self::KINDS,['files']);if(AuthoringScale::metadata($j['action'])){while($j['kind']<count($kinds)-1&&$j['counts'][$kinds[$j['kind']]]===0){$j['kind']++;$j['cursor']=0;}}$kind=$kinds[$j['kind']];$rows=$this->store->page($j['id'],$kind,$j['cursor']);$processed=0;
  foreach($rows as$r){if(microtime(true)-$start>16){break;}$j['verified_chain']=hash('sha256',$j['verified_chain'].$kind.':'.$r['id'].':'.$r['digest']);$j['verified']++;$j['cursor']=$r['id'];$processed++;}
  if(!$this->store->hasAfter($j['id'],$kind,$j['cursor'])){$j['kind']++;$j['cursor']=0;}
  if($j['kind']===count($kinds)){
   if($j['captured']!==array_sum($j['counts'])||$j['verified']!==$j['captured']||!hash_equals($j['chain'],$j['verified_chain'])){throw new \RuntimeException('Incomplete recovery cannot be sealed');}
   $j['sealed']=true;$j['seal']=self::seal($j);$j['phase']=AuthoringScale::metadata($j['action'])?'apply':($j['action']==='forms_transfer'?'copy_files':($j['action']==='forms_delete_form'?'remove_shares':'delete_files'));$j['kind']=0;$j['cursor']=0;
  }
 }
 private function applyPage(array &$j,float $start,callable $authorize):void {
  if(!$j['sealed']){throw new \RuntimeException('Unsealed recovery');}
  if(in_array($j['action'],AuthoringScale::ACTIONS,true)){Server::get(AuthoringScale::class)->apply($j,$authorize);return;}
  $kinds=$j['action']==='forms_delete_responses'?['answers','submissions']:($j['action']==='forms_delete_form'?['answers','submissions','options','questions','uploads','artifacts','shares','comments','form']:['form']);
  $kind=$kinds[$j['kind']];$rows=$this->store->page($j['id'],$kind,$j['cursor']);$processed=0;
  // Acquire the exact bounded source rows before admission, so a row wait cannot
  // resume inside a DELETE/UPDATE after current or original authority was revoked.
  if($rows!==[]){
   $pk=$kind==='artifacts'?'file_id':'id';$ids=array_column($rows,'id');
   $locked=$this->db->executeQuery('SELECT '.$pk.' FROM *PREFIX*'.self::TABLES[$kind].' WHERE '.$pk.' IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY '.$pk.' FOR UPDATE',$ids)->fetchAll();
   if(count($locked)!==count($ids)){throw new BeforeEffect('Exact bulk source rows changed');}
  }
  $this->admit($j['uid'],$j['action'],$j['input'],$authorize);BulkDeadline::check();
  foreach($rows as$r){if(microtime(true)-$start>16){break;}
   if($j['action']==='forms_transfer'){
    $finalSharing=null;$count=Server::get(BulkFiles::class)->commitTransfer($j,$start,function()use(&$j,$start,$r,&$finalSharing):int{
     Server::get(BulkFiles::class)->remap($j,$start);$finalSharing=Server::get(BulkTransferShares::class)->commit($j);Server::get(LinkedMarkers::class)->retire((int)$j['form']['id']);OriginContext::assertFresh();if(microtime(true)-$start>18){throw new BeforeEffect('Transfer mapping deadline exhausted');}
     return $this->db->executeStatement('UPDATE *PREFIX*forms_v2_forms SET owner_id = ?,locked_by = NULL,locked_until = NULL,file_id = NULL,file_format = NULL WHERE id = ? AND owner_id = ?',[$j['input']['owner_id'],$r['id'],$j['form']['owner_id']]);
    },static function()use(&$finalSharing):FinalTransferShareCheck{return $finalSharing??throw new \LogicException('Managed transfer final collector is unavailable');});
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
 private static function seal(array $j):string {$data=[$j['id'],$j['actor'],$j['counts'],$j['chain'],$j['form']];if(($j['backup_format']??1)===2){$data[]=['backup_format'=>2,'historical_unavailable_files'=>$j['historical_unavailable_files']];}return BulkStore::digest($data);}
 private function recovery(string $uid,string $id,mixed $format=1):array {
  if(!is_int($format)||!in_array($format,[1,2],true)){throw new BeforeEffect('Unknown requested recovery format');}
  if($format===2){HistoricalFiles::requireReady();}
  $b=$this->store->load($id);if(($b['purpose']??null)===NativeHistoryTransition::PURPOSE){throw new BeforeEffect('Native ownership provenance is not a recovery archive');}$this->actor($uid,$b);if(($b['backup_format']??0)!==$format){throw new BeforeEffect('Unknown bulk recovery format');}
  if(!$b['sealed']||!hash_equals($b['seal']??'',self::seal($b))){throw new BeforeEffect('Recovery is incomplete or corrupt');}
  $live=$this->db->executeQuery('SELECT owner_id FROM *PREFIX*forms_v2_forms WHERE id = ?',[$b['form']['id']])->fetchOne();
  if($live===false){if($b['form']['owner_id']!==$uid){throw new BeforeEffect('Recovery owner unavailable');}}
  else{$f=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$b['form']['id']);$p=(new ScopedServices($uid))->forms()->getPermissions($f);if($f->getOwnerId()!==$uid&&(!in_array('results',$p,true)||!in_array('results_delete',$p,true))){throw new BeforeEffect('Recovery authority revoked');}}
  if(!(new ScopedServices($uid))->get(\OCA\Forms\Service\ConfigService::class)->canCreateForms()){throw new BeforeEffect('Form creation unavailable');}return $b;
 }
 /** Historical archive is readable only through its completed, currently owned restored form. */
 public function commentsPage(string $uid,string $restoreId,int $cursor,int $limit):array {
  if($cursor<0||$limit<1||$limit>100){throw new BeforeEffect('Invalid historical comment page');}
  $j=$this->store->load($restoreId);$this->actor($uid,$j);
  if($j['action']!=='forms_restore'||$j['phase']!=='complete'||$j['state']!=='completed'||!is_int($j['restored_form_id'])){throw new BeforeEffect('Historical recovery is not complete');}
  $admit=function()use($uid,$j):array {
   OriginContext::assertFresh();$v=OriginContext::current()['verified'];
   if(!in_array($v['tool'],['forms_list_comments','forms_read_recovered_comments'],true)||(int)($v['references']['form_id']??0)!==$j['restored_form_id']){throw new BeforeEffect('Historical comment grant does not match restored form');}
   if(!Server::get(\OCA\Forms\Mcp\Guard::class)->capabilities()['comments']){throw new BeforeEffect('Historical comments runtime unavailable');}
   if(Server::get(\OCP\IUserSession::class)->getUser()?->getUID()!==$uid){throw new BeforeEffect('Historical comment actor changed');}
   $scope=new ScopedServices($uid);$form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($j['restored_form_id']);
   if($form->getOwnerId()!==$uid||!$scope->forms()->hasUserAccess($form)||!$form->getAllowComments()||!$scope->get(\OCA\Forms\Service\ConfigService::class)->getAllowComments()){throw new BeforeEffect('Historical comments unavailable to current actor');}
   $backup=$this->recovery($uid,$j['input']['backup_id'],$j['input']['backup_format']??1);if($this->db->executeQuery('SELECT id FROM *PREFIX*forms_v2_forms WHERE id = ?',[$backup['form']['id']])->fetchOne()!==false){$source=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$backup['form']['id']);if(!$source->getAllowComments()||!$scope->forms()->hasUserAccess($source)){throw new BeforeEffect('Live source comment authority is unavailable');}}return $backup;
  };
  $rev=$this->fence->revision();$b=$admit();$items=[];$after=$cursor;$bytes=0;$namespace='archive:'.$j['id'].':';
  foreach(array_slice($this->store->page($b['id'],'comments',$cursor),0,$limit)as$r){
   BulkDeadline::check();$d=$r['data'];if(($d['object_type']??null)!=='forms'||(int)$d['object_id']!==(int)$b['form']['id']){throw new BeforeEffect('Historical comment belongs to another form');}
   $author=$d['historical_author']??['type'=>$d['actor_type'],'id'=>$d['actor_id'],'identity_status'=>'unverified_historical'];$author=array_intersect_key($author,array_flip(['type','id','identity_status']));
   $parent=(int)($d['parent_id']??0);$root=(int)($d['topmost_parent_id']??0);
   $item=['id'=>$namespace.$r['id'],'source_id'=>$r['id'],'parent_id'=>$parent>0?$namespace.$parent:null,'root_id'=>$namespace.($root>0?$root:($parent>0?$parent:$r['id'])),'author'=>$author,'message'=>$d['message'],'verb'=>$d['verb'],'created_at'=>$d['creation_timestamp'],'latest_child_at'=>$d['latest_child_timestamp']??null,'expire_at'=>$d['expire_date']??null,'historical'=>true];
   $size=strlen(json_encode($item,JSON_THROW_ON_ERROR));if($size>1040000){throw new BeforeEffect('Historical comment exceeds page budget');}if($bytes+$size>1040000){break;}$bytes+=$size;$items[]=$item;$after=$r['id'];
  }
  $more=$this->store->hasAfter($b['id'],'comments',$after);$admit();BulkDeadline::check();if($rev!==$this->fence->revision()){throw new BeforeEffect('Historical comment authority changed during read');}
  return ['items'=>$items,'total'=>(int)($b['counts']['comments']??0),'next_cursor'=>$more?$after:null,'complete'=>!$more,'archive_id'=>$j['id'],'restored_form_id'=>$j['restored_form_id']];
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
    $fileHistory=$row['_mcp_file_history']??null;unset($row['_mcp_file_history']);
    if($row['file_id']){$meta=$this->store->one($backup,'files',(int)$row['file_id']);if(HistoricalFiles::isUnavailable($meta)){if($j['backup_format']!==2){throw new \RuntimeException('Unavailable file requires history format');}$fileHistory=['availability'=>'historical_unavailable','source_file_id'=>(int)$row['file_id'],'unavailable_cause'=>'unverified'];$row['file_id']=null;}else{$row['file_id']=$this->store->mapped($j['id'],'files',(int)$row['file_id']);}}
    if($fileHistory!==null&&$j['backup_format']!==2){throw new \RuntimeException('Historical provenance requires history format');}
    $question=$this->store->one($backup,'questions',$oldQ);$row['text']=$this->answerText($j,$question,$row['text']);
   }
   $new=$this->insert($kind,$row);if($kind==='form'){$j['restored_form_id']=$new;}
   if($kind==='answers'&&$fileHistory!==null){$history=['form_id'=>$j['restored_form_id'],'answer_id'=>$new,'availability'=>'historical_unavailable','source_file_id'=>$fileHistory['source_file_id'],'source_archive_id'=>$backup,'source_answer_id'=>$r['id'],'unavailable_cause'=>'unverified','owner'=>HistoricalFiles::ownerIdentity($j['uid'])];$this->store->putMapping($j,'file_history',$new,$history);$this->store->map($j['id'],'file_history',$new,$history['source_file_id']);}
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
  $sharePhase=AuthoringScale::metadata($j['action'])&&($j['share_events']??false)?Server::get(ManagedSharePhase::class)->phase($j['key']):null;
  $total=match($j['action']){'forms_delete_responses'=>$j['counts']['answers']+$j['counts']['submissions']+($j['artifact_deletions']??0),'forms_transfer','forms_update','forms_update_share','forms_unshare'=>1,'forms_delete_question'=>($j['question_effect_count']??1)+($j['artifact_deletions']??0),'forms_restore'=>array_sum(array_intersect_key($j['counts'],array_flip(['form','questions','options','submissions','answers']))),default=>array_sum($j['counts'])-$j['counts']['files']-($j['counts']['share_recipients']??0)};
  return ['bulk_id'=>$j['id'],'operation_key'=>$j['key'],'state'=>$j['state'],'phase'=>$j['phase'],'version'=>$j['version'],'progress'=>['done'=>$j['done'],'total'=>$total,'captured'=>$j['captured'],'capture_total'=>array_sum($j['counts'])],'references'=>['form_id'=>(int)$j['form']['id'],'backup_id'=>AuthoringScale::metadata($j['action'])?null:($j['sealed']?$j['id']:($j['input']['backup_id']??null)),'restored_form_id'=>$j['restored_form_id'],'backup_format'=>AuthoringScale::metadata($j['action'])?'authoring_effects_v1':(($j['backup_format']??1)===2?HistoricalFiles::FORMAT:'bulk_v1'),'evidence_id'=>$j['id'],'recovery_scope'=>AuthoringScale::metadata($j['action'])?'none':(($j['backup_format']??1)===2?'whole_form_with_unavailable_history':'whole_form')],'historical_unavailable_files'=>$j['historical_unavailable_files']??0,'retained_files'=>['historical_unavailable'=>$j['historical_unavailable_files']??0,'bytes_complete'=>($j['historical_unavailable_files']??0)===0,'recovery'=>$j['retained_files']['recovery']??0,'copies'=>$j['retained_files']['copies']??0,'shares'=>$j['retained_shares']??0,'recipient_states'=>$j['retained_recipients']??0,'folders'=>$j['retained_folders']??0,'evidence_id'=>$j['id'],'backup_sealed'=>!AuthoringScale::metadata($j['action'])&&$j['sealed']],'next'=>$j['state']==='pending','reason'=>$j['reason']??null,'phases'=>['database'=>$j['phase']==='complete'?'committed':($j['done']?'partially_committed':'not_started'),'files'=>$sharePhase!==null?($sharePhase==='native_files_shares_verified'?'verified':($sharePhase==='native_files_shares_pending'?'pending':'unknown')):($j['counts']['files']===0&&($j['counts']['managed_shares']??0)===0?'not_applicable':($j['phase']==='complete'?'verified':($j['state']==='uncertain'?'unknown':((array_sum($j['retained_files']??[])+($j['retained_shares']??0)+($j['retained_folders']??0))>0?'retained':'pending')))),'events'=>($j['share_events']??false)?[['kind'=>'files_share','phase'=>$sharePhase===null?($j['event_phase']??'attempted_unverified'):($sharePhase==='native_files_shares_verified'?'attempted_unverified':($sharePhase==='native_files_shares_pending'?'pending':'uncertain'))]]:[]]];
 }
}
