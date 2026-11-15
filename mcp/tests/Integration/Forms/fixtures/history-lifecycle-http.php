<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')exit(2);
$trackedOrigin=dirname(__DIR__,5).'/native/forms-guard/tests/origin-fixture.php';
$originFixture=getenv('MCP_FORMS_NATIVE_ORIGIN_FIXTURE')?:(is_file($trackedOrigin)?$trackedOrigin:'/tmp/origin-fixture.php');
if(!is_file($originFixture)||is_link($originFixture))exit(2);
require_once $originFixture;if(!function_exists('formsNativeOrigin'))exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';use OCP\Server;
$stage='bootstrap';$checks=[];$exitCode=0;$mcpDisabled=false;
try {
 Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
 $db=Server::get(\OCP\IDBConnection::class);$users=Server::get(\OCP\IUserManager::class);$session=Server::get(\OCP\IUserSession::class);
 $uid='history2-http-'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(24));
 $owner=['uid'=>$uid,'password'=>$password,'user'=>$users->createUser($uid,$password)];$session->setUser($owner['user']);
 formsNativeOrigin($uid,'forms_capabilities');
 // The disposable native-origin helper selects extended tools; select this legacy reader explicitly too.
 Server::get(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($uid):bool{Server::get(\OCA\Mcp\Db\ToolGrantsMapper::class)->set('user',$uid,'forms_read_responses','allow');return true;});
 $selection=Server::get(\OCA\Mcp\OAuth\ConnectionToolsStore::class);$selected=$selection->get($uid,0);$selection->save($uid,0,array_values(array_unique([...$selected['tools'],'forms_read_responses'])),$selected['revision'],$selected['variants']);

 $rpc=static function(array $actor,string $name,array $args):array {
  $c=curl_init('http://127.0.0.1/index.php/apps/mcp/');curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_USERPWD=>$actor['uid'].':'.$actor['password'],CURLOPT_HTTPHEADER=>['Host: localhost','Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-06-18'],CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],JSON_THROW_ON_ERROR)]);
  $body=curl_exec($c);$http=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);$r=json_decode((string)$body,true);if($http!==200||!is_array($r))throw new RuntimeException('Recovery HTTP transport failed.');if(isset($r['error']))return ['isError'=>true];return $r['result'];
 };
 $actor=$owner;
 $call=static function(string $tool,array $args)use($rpc,&$actor):array {$r=$rpc($actor,$tool,$args);if($r['isError']??false)throw new RuntimeException('Refused '.$tool.': '.($r['content'][0]['text']??''));return $r['structuredContent']??json_decode($r['content'][0]['text'],true,64,JSON_THROW_ON_ERROR);};
 $deny=static function(string $tool,array $args)use($rpc,&$actor):void {if(!($rpc($actor,$tool,$args)['isError']??false))throw new RuntimeException('Forbidden recovery reader admitted: '.$tool);};
 $write=static function(string $tool,array $args)use($call):array {$p=$call($tool,$args);return $call($tool,$args+['confirm'=>true,'confirm_shared'=>$p['requiresSharedConfirmation']??false,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]);};


 $complete=static function(string $tool,array $args)use($write,$call):array{
  $r=$write($tool,$args);$original=$r['operation']['operation_id'];
  for($i=0;($r['operation']['state']??null)==='pending'&&$i<100;$i++)$r=$write('forms_continue_operation',['target_operation_id'=>$original]);
  $refs=$r['operation']['references']??[];
  if(!($r['data']['complete']??false)||!in_array($r['operation']['state']??null,['succeeded','partial'],true)||($refs['database']??null)!=='committed'||!in_array($refs['files']??null,['verified','not_applicable'],true))throw new RuntimeException('Unverified terminal lifecycle.');
  if(($r['operation']['state']??null)==='partial'){if(($refs['notifications']??[])===[])throw new RuntimeException('Partial without explicit event evidence.');foreach($refs['notifications']as$event)if(($event['phase']??null)!=='attempted_unverified')throw new RuntimeException('Unknown partial event.');}
  $fresh=$call('forms_get_operation',['operation_id'=>$r['operation']['operation_id']]);
  if(($fresh['operation_id']??null)!==$r['operation']['operation_id']||($fresh['state']??null)!==$r['operation']['state']||\OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal::canonical($fresh['references']??[])!==\OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal::canonical($refs))throw new RuntimeException('Exact terminal HTTP receipt differs.');
  return $r;
 };
 $stage='native unavailable history fixture';$api=Server::get(\OCA\Forms\Controller\ApiController::class);$id=$api->newForm()->getData()['id'];
 $q=$api->newQuestion($id,'file',null,'Hidden file history')->getData()['id'];$text=$api->newQuestion($id,'short',null,'Retained text')->getData()['id'];
 $form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);$home=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
 $folder=$home->newFolder(Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form));$stray=$folder->newFile('unmanaged-history-keep.txt','unmanaged history canary');$files=[];$canaries=[];$sids=[];
 \OCA\Forms\Mcp\Service\FormsRevisionFence::run(function()use($id,$q,$text,$uid,$folder,$db,&$files,&$canaries,&$sids):void{
  for($i=0;$i<2;$i++){
   $name='private-history-'.$uid.'-'.$i.'.txt';$file=$folder->newFile($name,'historical bytes '.$i);$files[]=$file;$canaries[]=$name;
   $s=new \OCA\Forms\Db\Submission();$s->setFormId($id);$s->setUserId($uid);$s->setTimestamp(1794614400+$i);$s=Server::get(\OCA\Forms\Db\SubmissionMapper::class)->insert($s);$sids[]=(int)$s->getId();
   foreach([[$q,$name,(int)$file->getId()],[$text,'retained answer '.$i,null]]as[$qid,$value,$fileId]){$a=new \OCA\Forms\Db\Answer();$a->setSubmissionId($s->getId());$a->setQuestionId($qid);$a->setFileId($fileId);$a->setText($value);Server::get(\OCA\Forms\Db\AnswerMapper::class)->insert($a);}
   $db->executeStatement('INSERT INTO *PREFIX*forms_mcp_artifacts (form_id,file_id) VALUES (?,?)',[$id,$file->getId()]);
  }
 });
 $gold=$db->executeQuery('SELECT a.* FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id=? ORDER BY a.id',[$id])->fetchAll();
 $stage='public question hide preserves positive native history';$hidden=$complete('forms_delete_question',['id'=>$id,'question_id'=>$q]);
 if($db->executeQuery('SELECT a.* FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id=? ORDER BY a.id',[$id])->fetchAll()!==$gold||(int)$db->executeQuery('SELECT `order` FROM *PREFIX*forms_v2_questions WHERE id=?',[$q])->fetchOne()!==0)throw new RuntimeException('Question history changed.');
 foreach($files as$file)if($home->getById((int)$file->getId())!==[])throw new RuntimeException('Managed source byte remained live.');
 if($stray->getContent()!=='unmanaged history canary')throw new RuntimeException('Unmanaged source file changed.');$checks['public_hide_keeps_native_history_and_unmanaged_bytes']=true;
 $stage='public history2 archive preview';$preview=$call('forms_delete_form',['id'=>$id]);$impact=$preview['impact']['bulk']??[];
 if(($impact['historical_unavailable_files']??null)!==2||($impact['recovery_bytes_complete']??null)!==false||($impact['recovery_scope']??null)!=='whole_form_with_unavailable_history')throw new RuntimeException('Unavailable history preview misrepresents bytes.');
 $archived=$complete('forms_delete_form',['id'=>$id]);$backup=$archived['operation']['references']['backup_id']??'';
 if(!str_starts_with($backup,'bulk_history_v2:')||($archived['operation']['references']['backup_format']??null)!=='bulk_history_v2'||($archived['data']['retained_files']['bytes_complete']??null)!==false||$db->executeQuery('SELECT id FROM *PREFIX*forms_v2_forms WHERE id=?',[$id])->fetchOne()!==false)throw new RuntimeException('Version2 archive is not complete honest metadata.');
 $checks['public_history2_archive_reports_unavailable_bytes']=true;
 $stage='file-free restoration with empty Files scope';$policy=Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);$writer=Server::get(\OCA\Mcp\Access\ResourceScope\ResourceScopeWriter::class);$state=Server::get(\OCA\Mcp\Db\PolicyStateMapper::class);$oldScope=Server::get(\OCA\Mcp\Db\ResourceScopeMapper::class)->forModule($uid,'files');
 $oldCreate=$policy->granted($uid,'files','create');$oldItems=$oldScope===null?null:$oldScope['items'];
 $withoutFiles=static function(callable $body,?callable $afterScope=null)use($writer,$state,$uid,$policy,$oldCreate,$oldItems):mixed{
  try{
   $writer->setResourceScope($state->state()['revision'],$uid,$uid,'files',[]);if($afterScope!==null)$afterScope();
   $policy->setGrant($uid,'files','create',false);return $body();
  }finally{
   // Both exact preimages are restored independently, including partial setup failures.
   $cleanupErrors=[];
   try{$policy->setGrant($uid,'files','create',$oldCreate);}catch(Throwable $error){$cleanupErrors[]=get_class($error);}
   try{$writer->setResourceScope($state->state()['revision'],$uid,$uid,'files',$oldItems);}catch(Throwable $error){$cleanupErrors[]=get_class($error);}
   $actual=Server::get(\OCA\Mcp\Db\ResourceScopeMapper::class)->forModule($uid,'files');
   if($cleanupErrors!==[]||$policy->granted($uid,'files','create')!==$oldCreate||($actual===null?null:$actual['items'])!==$oldItems)throw new RuntimeException('Exact Files policy preimages were not restored.');
  }
 };
 $injected=false;try{$withoutFiles(static fn()=>throw new LogicException('Unexpected setup continuation.'),static fn()=>throw new RuntimeException('Exact disposable scope setup fault.'));}catch(RuntimeException $error){if($error->getMessage()!=='Exact disposable scope setup fault.')throw $error;$injected=true;}
 if(!$injected)throw new RuntimeException('Partial scope setup fault was not reached.');$checks['partial_scope_setup_restores_both_preimages']=true;
 $restored=$withoutFiles(static fn():array=>$complete('forms_restore',['backup_id'=>$backup]));
 $new=$restored['operation']['references']['restored_form_id'];
 $verify=static function(int $formId,string $ownerUid)use($db,$canaries,$uid):void{
  $f=$db->executeQuery('SELECT owner_id,state,access_enum,file_id,file_format FROM *PREFIX*forms_v2_forms WHERE id=?',[$formId])->fetch();
  if(!$f||$f['owner_id']!==$ownerUid||(int)$f['state']!==1||(int)$f['access_enum']!==0||$f['file_id']!==null||$f['file_format']!==null||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_shares WHERE form_id=?',[$formId])->fetchOne()!==0)throw new RuntimeException('History form is not owned private closed and unlinked.');
  $rows=$db->executeQuery('SELECT a.text,a.file_id,a.submission_id,q.type,q.`order`,q.form_id AS question_form_id,s.user_id,s.timestamp FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id JOIN *PREFIX*forms_v2_questions q ON q.id=a.question_id WHERE s.form_id=? ORDER BY s.timestamp,q.type',[$formId])->fetchAll();
  if(count($rows)!==4||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_submissions WHERE form_id=?',[$formId])->fetchOne()!==2||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_artifacts WHERE form_id=?',[$formId])->fetchOne()!==0)throw new RuntimeException('History row cardinality differs.');
  $seen=[];foreach($rows as$row){$i=(int)$row['timestamp']-1794614400;if(!in_array($i,[0,1],true)||(int)$row['question_form_id']!==$formId||$row['user_id']!==$uid||$row['file_id']!==null)throw new RuntimeException('History exact row binding differs.');
   $expected=$row['type']==='file'?$canaries[$i]:'retained answer '.$i;if($row['text']!==$expected||($row['type']==='file'&&(int)$row['order']!==0)||isset($seen[$i][$row['type']]))throw new RuntimeException('History exact values/question bindings differ.');$seen[$i][$row['type']]=(int)$row['submission_id'];
  }foreach($seen as$parts)if(count($parts)!==2||count(array_unique($parts))!==1)throw new RuntimeException('Mixed restored submission.');
 };
 $verify($new,$uid);$checks['file_free_history_restore_with_no_files_create_or_scope']=true;
 $stage='public file answer privacy after NULL restoration';$restoredSids=array_map('intval',array_column($db->executeQuery('SELECT id FROM *PREFIX*forms_v2_submissions WHERE form_id=? ORDER BY id',[$new])->fetchAll(),'id'));
 foreach($restoredSids as$sid){$public=$call('forms_read_response',['id'=>$new,'submission_id'=>$sid]);$legacy=$call('forms_read_responses',['id'=>$new]);foreach($canaries as$name)if(str_contains(json_encode([$public,$legacy],JSON_THROW_ON_ERROR),$name))throw new RuntimeException('NULL file filename leaked through native or legacy projection.');}
 $checks['native_and_legacy_http_hide_null_file_names']=true;
 $stage='native HTTP ownership transition while MCP disabled';$targetUid='history2-target-'.bin2hex(random_bytes(5));$targetPassword=bin2hex(random_bytes(24));$target=['uid'=>$targetUid,'password'=>$targetPassword,'user'=>$users->createUser($targetUid,$targetPassword)];
 if(Server::get(\OCP\IConfig::class)->getUserValue($targetUid,'mcp','forms_native_test_ready','')!=='')throw new RuntimeException('Target unexpectedly enrolled in MCP.');
 // NC35 AppManager's legacy mixed setter rejects the stored string type. CLI cannot
 // invalidate the web APCu segment; observe native HTTP on both sides of its 3-second config cache.
 $probeMcp=static function()use($owner):array{
  $c=curl_init('http://127.0.0.1/index.php/apps/mcp/');curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_USERPWD=>$owner['uid'].':'.$owner['password'],CURLOPT_HTTPHEADER=>['Host: localhost','Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-06-18'],CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list','params'=>new \stdClass()],JSON_THROW_ON_ERROR)]);
  $body=curl_exec($c);$http=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);$reply=is_string($body)?json_decode($body,true):null;if($body===false||!in_array($http,[200,404],true))throw new RuntimeException('MCP app switch HTTP control failed.');return ['http'=>$http,'has_tools'=>is_array($reply['result']['tools']??null)];
 };
 $awaitMcp=static function(bool $enabled)use($probeMcp):void{
  $deadline=microtime(true)+12;do{$r=$probeMcp();if($enabled?($r['http']===200&&$r['has_tools']):($r['http']===404&&!$r['has_tools']))return;usleep(200000);}while(microtime(true)<$deadline);throw new RuntimeException('Fresh HTTP did not observe the MCP app switch within its cache bound.');
 };
 $appConfig=Server::get(\OCP\IAppConfig::class);$priorMcpEnabled=$appConfig->getValueString('mcp','enabled','');if($priorMcpEnabled!=='yes')throw new RuntimeException('Unexpected MCP app switch preimage.');$awaitMcp(true);
 $restoreMcp=static function()use($appConfig,$priorMcpEnabled,$awaitMcp,&$mcpDisabled):void{$appConfig->setValueString('mcp','enabled',$priorMcpEnabled);if($appConfig->getValueString('mcp','enabled','')!==$priorMcpEnabled)throw new RuntimeException('MCP app switch restoration differs.');$awaitMcp(true);$mcpDisabled=false;};
 $mcpDisabled=true;$appConfig->setValueString('mcp','enabled','no');
 if($appConfig->getValueString('mcp','enabled','')!=='no')throw new RuntimeException('MCP app switch was not disabled.');
 try{
  $awaitMcp(false);
  $c=curl_init('http://127.0.0.1/ocs/v2.php/apps/forms/api/v3/forms/'.$new.'?format=json');curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>'PATCH',CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_USERPWD=>$owner['uid'].':'.$owner['password'],CURLOPT_HTTPHEADER=>['Host: localhost','OCS-APIRequest: true','Content-Type: application/json','Accept: application/json'],CURLOPT_POSTFIELDS=>json_encode(['keyValuePairs'=>['ownerId'=>$targetUid]],JSON_THROW_ON_ERROR)]);
  $body=curl_exec($c);$http=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);$result=json_decode((string)$body,true);
  if($http!==200||($result['ocs']['meta']['statuscode']??null)!==200)throw new RuntimeException('Native HTTP owner transfer refused.');
 }finally{$restoreMcp();}
 $verify($new,$targetUid);if(Server::get(\OCP\IConfig::class)->getUserValue($targetUid,'mcp','forms_native_test_ready','')!=='')throw new RuntimeException('Native UI transition enrolled target MCP.');
 $checks['native_http_owner_transfer_works_with_mcp_disabled_and_unenrolled_target']=true;
 $stage='new owner password-stable history and independent private backup';$session->setUser($target['user']);formsNativeOrigin($targetUid,'forms_capabilities');$actor=$target;$actor['password']=bin2hex(random_bytes(24));$target['user']->setPassword($actor['password']);
 // The exact same archive remains eligible to its original owner before and after cross-owner denial.
 $oldOwnerEligible=static function()use(&$actor,$owner,$call,$backup):void{
  $current=$actor;try{$actor=$owner;$positive=$call('forms_restore',['backup_id'=>$backup]);if(!isset($positive['plan_state'],$positive['operation_id'])||($positive['impact']['bulk']['historical_unavailable_files']??null)!==2)throw new RuntimeException('Old-owner archive eligibility positive control failed.');}finally{$actor=$current;}
 };
 $oldOwnerEligible();$deny('forms_restore',['backup_id'=>$backup]);$oldOwnerEligible();
 $newArchive=$complete('forms_delete_form',['id'=>$new]);$newBackup=$newArchive['operation']['references']['backup_id']??'';
 if(!str_starts_with($newBackup,'bulk_history_v2:')||$newBackup===$backup||($newArchive['data']['historical_unavailable_files']??null)!==2)throw new RuntimeException('Completed target history did not survive password change.');
 $freshRestore=$complete('forms_restore',['backup_id'=>$newBackup]);$again=$freshRestore['operation']['references']['restored_form_id'];$verify($again,$targetUid);
 if($stray->getContent()!=='unmanaged history canary')throw new RuntimeException('Later history transition changed unrelated source bytes.');
 $checks['new_owner_password_change_rearchive_and_restore']=true;$checks['old_owner_backup_remains_private']=true;
 echo json_encode(['ok'=>true,'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){echo json_encode(['ok'=>false,'stage'=>$stage,'error_class'=>get_class($e),'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";$exitCode=1;}
finally{if($mcpDisabled)$restoreMcp();}
exit($exitCode);
