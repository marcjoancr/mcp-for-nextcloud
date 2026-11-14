<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\{Server,IDBConnection};
use OCA\Forms\Mcp\BeforeEffect;
/** Action-specific capture contract: authoring inventory is never a whole-form recovery. */
final class AuthoringScale {
 public const ACTIONS=['forms_update','forms_update_share','forms_unshare','forms_delete_question'];
 public static function metadata(string $action):bool {return in_array($action,['forms_update','forms_update_share','forms_unshare'],true);}
 public static function filesRelevant(string $action,array $input):bool {
  if(!in_array($action,['forms_update_share','forms_unshare'],true)){return true;}
  $share=Server::get(\OCA\Forms\Db\ShareMapper::class)->findById((int)$input['share_id']);if($share->getFormId()!==(int)$input['id']){throw new BeforeEffect('Share is unavailable');}
  if(!in_array($share->getShareType(),[0,1,2,7],true)){return false;}
  if($action==='forms_update_share'&&!isset($input['permissions'])){return false;}
  if($action==='forms_update_share'&&in_array('results',$input['permissions'],true)){return true;}
  if($action==='forms_unshare'&&!in_array('results',$share->getPermissions(),true)){return false;}
  return Server::get(IDBConnection::class)->executeQuery('SELECT files_share_id FROM *PREFIX*forms_mcp_file_shares WHERE form_id = ? AND native_share_id = ? LIMIT 1',[$input['id'],$input['share_id']])->fetchOne()!==false;
 }
 public static function fingerprint(string $action,array $input,int $formId):string {return self::filesRelevant($action,$input)?Server::get(BulkFiles::class)->fingerprint($formId):str_repeat('0',64);}
 public static function assertOriginalFiles(array $manifest):void {
  if(($manifest['files_share']??false)&&!in_array('share',OriginContext::current()['verified']['references']['files_operations']??[],true)){throw new BeforeEffect('Original Files-sharing grant is required');}
 }
 public static function source(string $action,array $input,string $kind,array $query):array {
  if(!self::metadata($action)){return $query;}
  [$sql,$params,$pk]=$query;
  if($kind==='form'){return $query;}
  if(in_array($action,['forms_update_share','forms_unshare'],true)&&$kind==='shares'){$sql.=' AND x.id = ?';$params[]=$input['share_id'];}
  elseif(in_array($action,['forms_update_share','forms_unshare'],true)&&$kind==='managed_shares'&&self::filesRelevant($action,$input)){$sql.=' AND x.native_share_id = ?';$params[]=$input['share_id'];}
  else{$sql.=' AND 1 = 0';}
  return [$sql,$params,$pk];
 }
 public static function admit(string $action,array $input):void {
  if(in_array($action,self::ACTIONS,true)){$form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$input['id']);(new ScopedServices(OriginContext::current()['verified']['uid']))->forms()->obtainFormLock($form);}
  if($action==='forms_update'&&!isset($input['fields']['title'])){throw new BeforeEffect('Bounded authoring update requires a title field');}
  if($action==='forms_delete_question'){$q=Server::get(\OCA\Forms\Db\QuestionMapper::class)->findById((int)$input['question_id']);if($q->getFormId()!==(int)$input['id']||$q->getOrder()===0){throw new BeforeEffect('Question is unavailable');}}
 }


 public static function projection(string $action,array $input):array {
  if($action==='forms_update'){$form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById((int)$input['id'])->read();return ['fields_before'=>array_intersect_key($form,$input['fields']),'fields_after'=>$input['fields']];}
  if(in_array($action,['forms_update_share','forms_unshare'],true)){$share=Server::get(\OCA\Forms\Db\ShareMapper::class)->findById((int)$input['share_id']);return ['share_before'=>['id'=>$share->getId(),'type'=>$share->getShareType(),'recipient'=>$share->getShareType()===3?null:$share->getShareWith(),'public_link'=>$share->getShareType()===3,'permissions'=>$share->getPermissions()],'share_after'=>$action==='forms_unshare'?null:['permissions'=>$input['permissions']??$share->getPermissions(),'token_changed'=>isset($input['token'])]];}
  if($action==='forms_delete_question'){$q=Server::get(\OCA\Forms\Db\QuestionMapper::class)->findById((int)$input['question_id']);return ['question_before'=>$q->read()];}return [];
 }
 public function __construct(private IDBConnection $db,private BulkStore $store){}
 public static function previewManifest(string $action,array $input):array {
  if(!in_array($action,['forms_update_share','forms_unshare'],true)||!self::filesRelevant($action,$input)){return ['files_share'=>false];}
  $files=[];$cursor=0;
  do{$page=Server::get(BulkFiles::class)->previewFiles((int)$input['id'],$cursor,100);foreach($page as$f){$cursor=$f['file_id'];if(HistoricalFiles::isUnavailable($f)){continue;}$files[]=$f;if(count($files)>1000){throw new BeforeEffect('Authoring Files count exceeds lifecycle budget');}}}while(count($page)===100);
  $impact=ManagedShares::snapshot((int)$input['id'],$files,$input);
  return ['files_share'=>($impact['create']??false)||($impact['managed_shares']??[])!==[],'share_impact'=>$impact];
 }
 public function apply(array &$j,callable $authorize):void {
  if($j['action']==='forms_delete_question'){$this->question($j,$authorize);return;}
  $files=[];$cursor=0;
  while($page=$this->store->page($j['id'],'files',$cursor)){foreach($page as$row){$m=$row['data'];$cursor=$row['id'];if(HistoricalFiles::isUnavailable($m)){continue;}unset($m['delete'],$m['delete_artifact']);$files[]=$m;BulkDeadline::check();}}
  $impact=self::previewManifest($j['action'],$j['input']);if($impact!==$j['authoring_manifest']){throw new BeforeEffect('Approved authoring folder or share identity changed');}
  self::assertOriginalFiles($impact);$manifest=$impact+['form_id'=>(int)$j['form']['id'],'files'=>$files,'delete_file_ids'=>[],'folders'=>'retained','recovery'=>false,'authoring_effects'=>true];
  if(!hash_equals($j['file_revision'],self::fingerprint($j['action'],$j['input'],(int)$j['form']['id']))){throw new BeforeEffect('Authoring Files changed before native effect');}
  $this->db->executeQuery('SELECT id FROM *PREFIX*forms_v2_forms WHERE id = ? FOR UPDATE',[$j['form']['id']])->fetchOne();
  if(isset($j['input']['share_id'])){$this->db->executeQuery('SELECT id FROM *PREFIX*forms_v2_shares WHERE id = ? FOR UPDATE',[$j['input']['share_id']])->fetchOne();}
  $authorize();OriginContext::assertFresh();OriginContext::assertFiles($j['action'],$manifest);BulkDeadline::check();
  $phase=Server::get(ManagedSharePhase::class);$phase->operation($j['key']);
  try{OriginContext::withFileImpact($j['action'],$manifest,fn()=>$phase->authoringStep(fn()=>FileRecovery::retainFolders(fn()=>NativeActions::invoke($j['action'],$j['input']))));}
  finally{$phase->operation(null);}
  $j['source_effects']=true;$j['done']=1;$j['phase']='complete';$j['share_events']=($manifest['files_share']??false);$j['state']=$j['share_events']?'partial':'completed';$j['event_phase']=$j['share_events']?'pending':null;
 }
 private function question(array &$j,callable $authorize):void {
  $qid=(int)$j['input']['question_id'];$kinds=['answers','options','uploads'];
  if($j['kind']<count($kinds)){
   $kind=$kinds[$j['kind']];$table=['answers'=>'forms_v2_answers','options'=>'forms_v2_options','uploads'=>'forms_v2_uploaded_files'][$kind];
   $page=$this->store->page($j['id'],$kind,$j['cursor']);$expected=[];foreach($page as$row){if((int)$row['data']['question_id']===$qid){$expected[$row['id']]=$row['digest'];}}
   $locked=[];if($expected!==[]){$locked=$this->db->executeQuery('SELECT * FROM *PREFIX*'.$table.' WHERE question_id = ? AND id IN ('.implode(',',array_fill(0,count($expected),'?')).') ORDER BY id FOR UPDATE',[$qid,...array_keys($expected)])->fetchAll();if(count($locked)!==count($expected)){throw new BeforeEffect('Approved question history changed');}}
   $authorize();OriginContext::assertFresh();BulkDeadline::check();
   foreach($locked as$row){if($kind==='answers'){$row=HistoricalFiles::decorateAnswer($row,(int)$j['form']['id']);}if($kind==='uploads'){unset($row['upload_token']);}if(!hash_equals($expected[(int)$row['id']],BulkStore::digest($row))){throw new BeforeEffect('Approved question history changed');}}
   $j['question_children'][$kind]=($j['question_children'][$kind]??0)+count($locked);
   foreach($page as$row){$j['cursor']=$row['id'];}
   if(!$this->store->hasAfter($j['id'],$kind,$j['cursor'])){$j['kind']++;$j['cursor']=0;}
   return;
  }
  $this->db->executeQuery('SELECT id FROM *PREFIX*forms_v2_questions WHERE id = ? AND form_id = ? FOR UPDATE',[$qid,$j['form']['id']])->fetchOne();
  $authorize();OriginContext::assertFresh();BulkDeadline::check();$this->assertHistoryCounts($j);
  if(!FileRecovery::skipDeferred(['formId'=>$j['form']['id'],'questionId'=>$qid])){$this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_file_guards (form_id,question_id) VALUES (?,?)',[$j['form']['id'],$qid]);}
  FileRecovery::retainFolders(fn()=>NativeActions::invoke($j['action'],$j['input']));
  $this->assertHistoryCounts($j);$j['done']++;$j['source_effects']=true;$j['phase']='complete';$j['state']='completed';
 }
 private function assertHistoryCounts(array $j):void {
  foreach(['answers'=>'forms_v2_answers','options'=>'forms_v2_options','uploads'=>'forms_v2_uploaded_files']as$kind=>$table){if((int)$this->db->executeQuery('SELECT COUNT(*) FROM *PREFIX*'.$table.' WHERE question_id = ?',[$j['input']['question_id']])->fetchOne()!==($j['question_children'][$kind]??0)){throw new BeforeEffect('Unapproved question history prevents final hiding');}}
 }
}
