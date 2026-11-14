<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Forms\Mcp\Service\{BulkStore,OriginContext};
function child(array $request):array {
 $p=proc_open([PHP_BINARY,__FILE__,'child'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 fwrite($pipes[0],json_encode($request,JSON_THROW_ON_ERROR)."\n");fclose($pipes[0]);return [$p,$pipes];
}
function finish(array $child):array {[$p,$pipes]=$child;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0){throw new RuntimeException($err.' '.$out);}return json_decode($out,true,64,JSON_THROW_ON_ERROR);}
try {
 Server::get(\OCP\App\IAppManager::class)->loadApp('forms');Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
 $uid='guard-owner';$db=Server::get(\OCP\IDBConnection::class);
 if(($argv[1]??'')==='child') {
  $r=json_decode(fgets(STDIN),true,64,JSON_THROW_ON_ERROR);
  if($r['mode']==='assert') {try{Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->assert($r['origin']);if(isset($r['manifest'])){Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->assertFiles($r['origin'],'forms_delete_form',$r['manifest']);}$denied=false;}catch(Throwable){$denied=true;}echo json_encode(['denied'=>$denied]);exit;}
  if($r['mode']==='revoke') {
   if($r['policy']==='resource'){Server::get(\OCA\Mcp\Access\ResourceScope\ResourceScopeWriter::class)->setResourceScope(Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['revision'],$uid,$uid,'files',[]);}
   elseif($r['policy']==='selection'){$s=Server::get(\OCA\Mcp\OAuth\ConnectionToolsStore::class);$old=$s->get($uid,0);$s->save($uid,0,array_values(array_diff($old['tools'],[$r['tool']])),$old['revision'],$old['variants']);}
   else {Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class)->setGrant($uid,$r['module']??'forms',$r['operation'],false);}
   echo json_encode(['committed'=>true]);exit;
  }
  $db->beginTransaction();
  if($r['lock']==='remap'){$db->executeQuery('SELECT id FROM *PREFIX*forms_v2_answers WHERE question_id = ? ORDER BY id FOR UPDATE',[$r['question']])->fetchAll();}
  elseif($r['lock']==='target'){$db->executeQuery('SELECT uid FROM *PREFIX*users WHERE uid = ? FOR UPDATE',[$r['target']])->fetchOne();}
  elseif($r['lock']==='file'){$db->executeQuery('SELECT id FROM *PREFIX*file_locks WHERE key = ? FOR UPDATE',[$r['file_key']])->fetchOne();}
  elseif($r['lock']==='source'){$db->executeQuery('SELECT id FROM *PREFIX*forms_v2_questions WHERE id = ? FOR UPDATE',[$r['question']])->fetchOne();}
  elseif($r['lock']==='advisory'){$db->executeQuery('SELECT pg_advisory_xact_lock(1179603533,1)')->fetchOne();}
  else {$db->executeQuery('SELECT id FROM *PREFIX*forms_mcp_fence WHERE id = 1 FOR UPDATE')->fetchOne();}
  echo "LOCKED\n";flush();$waited=false;$deadline=microtime(true)+8;
  while(microtime(true)<$deadline){$db->executeQuery('SELECT pg_stat_clear_snapshot()')->fetchOne();if($db->executeQuery('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?',[$r['pid']])->fetchOne()==='Lock'){$waited=true;break;}usleep(10000);}
  if(!$waited){throw new RuntimeException('Operation never waited on actual native SQL lock');}
  $written=finish(child(['mode'=>'revoke']+$r));if(!($written['committed']??false)){throw new RuntimeException('Policy writer did not commit');}
  $fresh=finish(child(['mode'=>'assert','origin'=>$r['origin']]+(isset($r['manifest'])?['manifest'=>$r['manifest']]:[])));$db->commit();echo json_encode(['waited'=>true,'fresh_denied'=>$fresh['denied']]);exit;
 }
 $variant=getenv('MCP_CONTROL_CASE')?:'continue_grant';$cancel=str_starts_with($variant,'cancel');$original=str_contains($variant,'original');$source=str_ends_with($variant,'_source');$fileCase=str_contains($variant,'file');$remap=str_contains($variant,'remap');$transfer=str_contains($variant,'target')||$remap;$action=$transfer?'forms_transfer':'forms_delete_form';$target='guard-share-recipient';
 Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));
 require '/tmp/origin-fixture.php';$config=Server::get(\OCP\IConfig::class);$config->deleteUserValue($uid,'mcp','forms_native_test_ready');
 $g=Server::get(\OCA\Forms\Mcp\Guard::class);$api=Server::get(\OCA\Forms\Controller\ApiController::class);$id=$api->newForm()->getData()['id'];$question=$api->newQuestion($id,'short',null,'Control wait')->getData()['id'];
 $file=null;$fileKey=null;
 if($fileCase){
  $question=$api->newQuestion($id,'file',null,'Managed file')->getData()['id'];$root=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);$folder=$root->newFolder(Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form));$file=$folder->newFile('wait.txt','retained authority bytes');
  \OCA\Forms\Mcp\Service\FormsRevisionFence::run(function()use($id,$question,$file,$uid){$submission=new \OCA\Forms\Db\Submission();$submission->setFormId($id);$submission->setUserId($uid);$submission->setTimestamp(time());$submission=Server::get(\OCA\Forms\Db\SubmissionMapper::class)->insert($submission);$answer=new \OCA\Forms\Db\Answer();$answer->setQuestionId($question);$answer->setSubmissionId($submission->getId());$answer->setFileId($file->getId());$answer->setText($file->getName());Server::get(\OCA\Forms\Db\AnswerMapper::class)->insert($answer);});
  $fileKey='files/'.md5($file->getStorage()->getId().'::'.trim($file->getInternalPath(),'/'));
 }
 $input=['id'=>$id];if($transfer){$input['owner_id']=$target;}$input['_mcp_origin']=formsNativeOrigin($uid,$action,$input);$origin=$input['_mcp_origin'];if($fileCase&&!str_contains($variant,'resource')){$origin=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsOriginProof::class)->create($uid,0,$action,$origin['operations'],['form_id'=>$id,'owner_id'=>$uid,'files_operations'=>$transfer?['read','create','edit']:['read','edit']]);$input['_mcp_origin']=$origin;}$s=$g->bulkSnapshot($uid,$action,$input);$j=$g->bulkStart($uid,$action,$input,$s['revision'],bin2hex(random_bytes(16)));
 if($source){for($i=0;$i<100;$i++){$stored=Server::get(BulkStore::class)->load($j['bulk_id']);if($stored['phase']==='apply'&&$stored['kind']===3){break;}$j=$g->bulkStep($uid,$j['bulk_id'],$origin,$j['version'],bin2hex(random_bytes(16)));}if($stored['kind']!==3){throw new RuntimeException('Question deletion phase not reached');}}
 if($fileCase){$effectPhase=$transfer?'apply':'delete_files';for($i=0;$i<100&&$j['phase']!==$effectPhase;$i++){$j=$g->bulkStep($uid,$j['bulk_id'],$origin,$j['version'],bin2hex(random_bytes(16)));}if($j['phase']!==$effectPhase){throw new RuntimeException('Managed deletion phase not reached');}}
 $tool=$cancel?'forms_cancel_operation':'forms_continue_operation';$operation=$cancel?'cancel_operations':'continue_operations';
 $authority=['tool'=>$action,'required_operations'=>$origin['operations'],'files_operations'=>$origin['references']['files_operations']??[]];
 $control=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->capture($uid,$tool,['id'=>$id],['bulk_authority'=>$authority]);
 $trigger=null;
 if($source||$transfer){
  $trigger='mcp_control_'.bin2hex(random_bytes(5));$db->executeStatement('CREATE SEQUENCE '.$trigger);
  $column=$transfer?'question_id':'id';$triggerTable=$transfer?'forms_v2_answers':'forms_v2_questions';$triggerEvent=$transfer?'UPDATE':'DELETE';
  $body='BEGIN IF OLD.'.$column.' = '.$question.' THEN PERFORM nextval('.chr(39).$trigger.chr(39).'); END IF; RETURN OLD; END';
  $db->executeStatement('CREATE FUNCTION '.$trigger.'() RETURNS trigger LANGUAGE plpgsql AS $$ '.$body.' $$');
  $db->executeStatement('CREATE TRIGGER '.$trigger.' BEFORE '.$triggerEvent.' ON *PREFIX*'.$triggerTable.' FOR EACH ROW EXECUTE FUNCTION '.$trigger.'()');
 }
 if($variant==='mismatch'){
  $proof=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class);$before=Server::get(BulkStore::class)->load($j['bulk_id']);$checks=[];
  $foreign=$api->newForm()->getData()['id'];
  foreach(['wrong_control'=>$proof->capture($uid,'forms_cancel_operation',['id'=>$id],['bulk_authority'=>$authority]),'wrong_form'=>$proof->capture($uid,$tool,['id'=>$foreign],['bulk_authority'=>$authority]),'wrong_original'=>$proof->capture($uid,$tool,['id'=>$id],['bulk_authority'=>['tool'=>'forms_delete_responses','required_operations'=>['delete_all_responses'],'files_operations'=>[]]])]as$name=>$bad){$denied=false;try{$g->bulkStep($uid,$j['bulk_id'],$bad,$j['version'],bin2hex(random_bytes(16)));}catch(\OCA\Forms\Mcp\BeforeEffect){$denied=true;}$checks[$name]=$denied;}
  $checks['no_mutation']=$before===Server::get(BulkStore::class)->load($j['bulk_id']);$checks['context_restored']=!OriginContext::active();echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks]);exit;
 }
 $before=Server::get(BulkStore::class)->load($j['bulk_id']);$beforeRows=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_bulk_rows WHERE bulk_id = ?',[$j['bulk_id']])->fetchOne();$key=bin2hex(random_bytes(16));
 $oldScope=null;if(str_contains($variant,'resource')){$oldScope=Server::get(\OCA\Mcp\Db\ResourceScopeMapper::class)->forModule($uid,'files');register_shutdown_function(static function()use($uid,$oldScope):void{Server::get(\OCA\Mcp\Access\ResourceScope\ResourceScopeWriter::class)->setResourceScope(Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['revision'],$uid,$uid,'files',$oldScope===null?null:$oldScope['items']);});}
 $barrier=child(['mode'=>'lock','target'=>$target,'file_key'=>$fileKey,'lock'=>$remap?'remap':($transfer?'target':($fileCase?'file':($source?'source':(str_contains($variant,'advisory')?'advisory':'fence')))),'question'=>$question,'pid'=>(int)$db->executeQuery('SELECT pg_backend_pid()')->fetchOne(),'tool'=>$original?'forms_delete_form':$tool,'module'=>str_contains($variant,'scope')?'files':'forms','operation'=>str_contains($variant,'scope')?'edit':($original?'delete_form':$operation),'policy'=>str_contains($variant,'resource')?'resource':(str_contains($variant,'selection')?'selection':'grant'),'origin'=>$control]+(str_contains($variant,'resource')?['manifest'=>['files'=>[['file_id'=>(int)$file->getId()]]]]:[]));
 if(trim(fgets($barrier[1][1]))!=='LOCKED'){throw new RuntimeException('Barrier failed');}
 $denied=false;$message='';$result=null;$boundaryCalls=0;$filesBoundary=$fileCase?function(string $tool,array $manifest)use($control,&$boundaryCalls):void{$boundaryCalls++;Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->assertFiles($control,$tool,$manifest);}:null;try{if($cancel){$g->bulkCancel($uid,$j['bulk_id'],$control,$j['version'],$key);}else{$result=$g->bulkStep($uid,$j['bulk_id'],$control,$j['version'],$key,$filesBoundary);}}catch(Throwable $e){$denied=true;$message=$e->getMessage();}
 $barrierResult=finish($barrier);$after=Server::get(BulkStore::class)->load($j['bulk_id']);
 $checks=['real_wait'=>$barrierResult['waited'],'fresh_child_denied'=>$barrierResult['fresh_denied'],'warm_parent_denied'=>$denied,'job_unchanged'=>$before===$after,'receipt_absent'=>Server::get(BulkStore::class)->receipt($j['bulk_id'],$key)===null,'recovery_rows_unchanged'=>$beforeRows===(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_bulk_rows WHERE bulk_id = ?',[$j['bulk_id']])->fetchOne(),'source_question_retained'=>$db->executeQuery('SELECT id FROM *PREFIX*forms_v2_questions WHERE id = ?',[$question])->fetchOne()!==false,'context_restored'=>!OriginContext::active()];
 if($fileCase&&!$transfer){$checks['trusted_file_callback_used']=$boundaryCalls>0;$checks['warm_parent_denied']=$result['state']==='uncertain';$checks['job_unchanged']=$after['version']===$before['version']+1&&($after['claim']['step_key']??null)===$key;$checks['source_bytes_retained']=$file->getContent()==='retained authority bytes';$checks['sealed_backup_retained']=$after['sealed']===true;$config->deleteUserValue($uid,'mcp','forms_native_test_ready');formsNativeOrigin($uid,'forms_delete_form',$input);$checks['same_claim_never_replays']=$g->bulkLookup($uid,$key,$origin,$j['bulk_id'],$before['version'])['state']==='uncertain';}
 if($trigger!==null){$checks['no_source_write_attempt']=$db->executeQuery('SELECT is_called FROM '.$trigger)->fetchOne()===false;$db->executeStatement('DROP TRIGGER '.$trigger.' ON *PREFIX*'.$triggerTable);$db->executeStatement('DROP FUNCTION '.$trigger.'()');$db->executeStatement('DROP SEQUENCE '.$trigger);}
 if($variant==='continue_grant'){
  $other='guard-share-recipient';$session=Server::get(\OCP\IUserSession::class);$session->setUser(Server::get(\OCP\IUserManager::class)->get($other));
  try{$otherInput=['_mcp_origin'=>formsNativeOrigin($other,'forms_create')];$preview=$g->snapshot($other,'forms_create',$otherInput);$g->apply($other,'forms_create',$otherInput,$preview['revision'],bin2hex(random_bytes(16)));$checks['different_actor_not_contaminated']=true;}finally{$session->setUser(Server::get(\OCP\IUserManager::class)->get($uid));}
 }
 // Restore only disposable fixture authority, and prove an ordinary UI write remains available.
 $config->deleteUserValue($uid,'mcp','forms_native_test_ready');formsNativeOrigin($uid,'forms_delete_form',$input);$api->newForm();
 echo json_encode(['ok'=>!in_array(false,$checks,true),'variant'=>$variant,'checks'=>$checks,'rejection'=>$message],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'line'=>$e->getLine(),'file'=>$e->getFile(),'trace'=>$e->getTraceAsString()],JSON_THROW_ON_ERROR)."\n";exit(1);}
