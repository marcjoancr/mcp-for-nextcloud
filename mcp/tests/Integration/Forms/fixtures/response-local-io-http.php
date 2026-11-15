<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_LOCAL_IO_BOUNDARY')!=='1')exit(2);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')exit(2);
$trackedOrigin=dirname(__DIR__,5).'/native/forms-guard/tests/origin-fixture.php';
$originFixture=getenv('MCP_FORMS_NATIVE_ORIGIN_FIXTURE')?:(is_file($trackedOrigin)?$trackedOrigin:'/tmp/origin-fixture.php');
if(!is_file($originFixture)||is_link($originFixture))exit(2);
require_once $originFixture;if(!function_exists('formsNativeOrigin'))exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';use OCP\Server;
$stage='bootstrap';$checks=[];$exitCode=0;$triggerName=null;
try {
 Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
 $db=Server::get(\OCP\IDBConnection::class);$users=Server::get(\OCP\IUserManager::class);$session=Server::get(\OCP\IUserSession::class);
 $uid='replacement-http-'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(24));
 $owner=['uid'=>$uid,'password'=>$password,'user'=>$users->createUser($uid,$password)];$session->setUser($owner['user']);
 formsNativeOrigin($uid,'forms_capabilities');
 $rpc=static function(array $actor,string $name,array $args):array {
  $c=curl_init('http://127.0.0.1/index.php/apps/mcp/');curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_USERPWD=>$actor['uid'].':'.$actor['password'],CURLOPT_HTTPHEADER=>['Host: localhost','Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-06-18'],CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],JSON_THROW_ON_ERROR)]);
  $body=curl_exec($c);$http=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);$r=json_decode((string)$body,true);if($http!==200||!is_array($r))throw new RuntimeException('Recovery HTTP transport failed.');if(isset($r['error']))return ['isError'=>true];return $r['result'];
 };
 $call=static function(string $tool,array $args,?array $actor=null)use($rpc,$owner):array {$r=$rpc($actor??$owner,$tool,$args);if($r['isError']??false)throw new RuntimeException('Refused '.$tool.': '.($r['content'][0]['text']??''));return $r['structuredContent']??json_decode($r['content'][0]['text'],true,64,JSON_THROW_ON_ERROR);};
 $deny=static function(string $tool,array $args,?array $actor=null)use($rpc,$owner):void {if(!($rpc($actor??$owner,$tool,$args)['isError']??false))throw new RuntimeException('Forbidden recovery reader admitted: '.$tool);};
 $write=static function(string $tool,array $args)use($call):array {$p=$call($tool,$args);return $call($tool,$args+['confirm'=>true,'confirm_shared'=>$p['requiresSharedConfirmation']??false,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]);};

 $position=(int)(getenv('MCP_FORMS_FILE_FAULT_POSITION')?:'1');if(!in_array($position,[1,2],true))throw new RuntimeException('Choose file fault position1or2.');
 $stage='native original response';$api=Server::get(\OCA\Forms\Controller\ApiController::class);
 $id=$api->newForm()->getData()['id'];$qid=$api->newQuestion($id,'file',null,'Exact response attachment')->getData()['id'];
 $form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);$home=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
 $folder=$home->newFolder(Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form));
 $stray=$folder->newFile('unmanaged-retained.txt','unmanaged retention canary');
 $old=$folder->newFile('original-response.txt','original answer attachment');
 $sid=\OCA\Forms\Mcp\Service\FormsRevisionFence::run(function()use($id,$qid,$uid,$old,$db):int{
  $s=new \OCA\Forms\Db\Submission();$s->setFormId($id);$s->setUserId($uid);$s->setTimestamp(time()-10);$s=Server::get(\OCA\Forms\Db\SubmissionMapper::class)->insert($s);
  $a=new \OCA\Forms\Db\Answer();$a->setSubmissionId($s->getId());$a->setQuestionId($qid);$a->setFileId($old->getId());$a->setText($old->getName());Server::get(\OCA\Forms\Db\AnswerMapper::class)->insert($a);
  $db->executeStatement('INSERT INTO *PREFIX*forms_mcp_artifacts (form_id,file_id) VALUES (?,?)',[$id,$old->getId()]);return (int)$s->getId();
 });
 $stage='public stage replacement sources';$handles=[];$staged=[];$gold=[];
 foreach([1,2]as$i){$source=$home->newFile('replacement-source-'.$i.'.txt','replacement bytes '.$i);$gold[]=hash('sha256','replacement bytes '.$i);
  $uploaded=$write('forms_upload_response_files',['id'=>$id,'question_id'=>$qid,'paths'=>['/'.$source->getName()]]);
  $handle=$uploaded['data']['files'][0]['handle']??throw new RuntimeException('No staged handle.');$handles[]=$handle;
  $upload=$db->executeQuery('SELECT u.file_id FROM *PREFIX*forms_mcp_uploads m JOIN *PREFIX*forms_v2_uploaded_files u ON u.id=m.upload_id WHERE m.handle_hash=?',[hash('sha256',$handle)])->fetch();
  $node=$home->getById((int)$upload['file_id'])[0];$staged[]=['id'=>(int)$node->getId(),'path'=>$node->getInternalPath(),'name'=>$node->getName(),'storage'=>$node->getStorage()];
 }
 $original=$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_submissions WHERE id=?',[$sid])->fetch();
 $oldAnswers=$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_answers WHERE submission_id=? ORDER BY id',[$sid])->fetchAll();
 $beforeUploads=$db->executeQuery('SELECT u.* FROM *PREFIX*forms_v2_uploaded_files u WHERE form_id=? ORDER BY id',[$id])->fetchAll();
 $beforeHandles=$db->executeQuery('SELECT handle_hash,consumed FROM *PREFIX*forms_mcp_uploads WHERE form_id=? ORDER BY handle_hash',[$id])->fetchAll();
 $question=Server::get(\OCA\Forms\Db\QuestionMapper::class)->findById($qid);
 $destination=Server::get(\OCA\Forms\Service\FormsService::class)->getUploadedFilePath($form,$sid,$qid,$question->getName(),$question->getText());
 $destinationInternal=trim($home->getInternalPath(),'/').'/'.$destination;
 $tool='forms_update_response';$args=['id'=>$id,'submission_id'=>$sid,'answers'=>[['question_id'=>$qid,'file_handles'=>$handles]]];
 $stage='valid response replacement approval';$preview=$call($tool,$args);
 if(!isset($preview['plan_state'],$preview['operation_id']))throw new RuntimeException('Replacement preview missing.');
 $confirm=$args+['confirm'=>true,'confirm_shared'=>$preview['requiresSharedConfirmation']??false,'plan_state'=>$preview['plan_state'],'operation_id'=>$preview['operation_id']];

 $target=$staged[$position-1];$local=$target['storage']->getLocalFile($target['path']);
 if(!is_string($local)||!str_starts_with($local,'/var/www/html/data/'.$uid.'/files/'))throw new RuntimeException('Qualified local path unavailable.');
 echo json_encode(['control'=>'inject','uid'=>$uid,'file_id'=>$target['id'],'path'=>$local],JSON_THROW_ON_ERROR)."\n";fflush(STDOUT);
 if(trim((string)fgets(STDIN))!=='injected')throw new RuntimeException('OS failure injector unavailable.');
 $log=Server::get(\OCP\IConfig::class)->getSystemValue('logfile','/var/www/html/data/nextcloud.log');
 if(!is_string($log)||!is_file($log)||is_link($log))throw new RuntimeException('Bounded native logfile unavailable.');
 clearstatcache(true,$log);$logStart=filesize($log);
 $stage='real native local rename failure';$failed=$call($tool,$confirm);
 echo json_encode(['control'=>'restore'],JSON_THROW_ON_ERROR)."\n";fflush(STDOUT);
 if(trim((string)fgets(STDIN))!=='restored')throw new RuntimeException('OS failure preimages were not restored.');
 if(($failed['operation']['state']??null)!=='uncertain')throw new RuntimeException('Replacement did not remain uncertain.');
 $sameSql=static function()use($db,$sid,$id,$original,$oldAnswers,$beforeUploads,$beforeHandles):bool{
  return $db->executeQuery('SELECT * FROM *PREFIX*forms_v2_submissions WHERE id=?',[$sid])->fetch()===$original
   &&$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_answers WHERE submission_id=? ORDER BY id',[$sid])->fetchAll()===$oldAnswers
   &&$db->executeQuery('SELECT u.* FROM *PREFIX*forms_v2_uploaded_files u WHERE form_id=? ORDER BY id',[$id])->fetchAll()===$beforeUploads
   &&$db->executeQuery('SELECT handle_hash,consumed FROM *PREFIX*forms_mcp_uploads WHERE form_id=? ORDER BY handle_hash',[$id])->fetchAll()===$beforeHandles;
 };
 if(!$sameSql())throw new RuntimeException('Original response or staging SQL did not roll back exactly.');$checks['original_response_and_upload_rows_rolled_back']=true;

 $physical=static function()use($staged,$destinationInternal,$position,$gold):array{
  $proof=[];foreach($staged as$i=>$row){$storage=$row['storage'];$source=$row['path'];$destination=$destinationInternal.'/'.$row['name'];
   $expectSource=$i>=($position-1);$expectDestination=$i<=$position-1;
   if($storage->file_exists($source)!==$expectSource||$storage->file_exists($destination)!==$expectDestination)throw new RuntimeException('Exact fallback copy/delete fault locations differ.');
   foreach(array_filter([$expectSource?$source:null,$expectDestination?$destination:null])as$path){
    $stream=$storage->fopen($path,'rb');if(!is_resource($stream))throw new RuntimeException('Physical bytes unreadable.');try{$hash=hash_init('sha256');hash_update_stream($hash,$stream);$sha=hash_final($hash);}finally{fclose($stream);}if($sha!==$gold[$i])throw new RuntimeException('Retained fallback bytes changed.');$proof[]=['id'=>$row['id'],'path'=>$path,'sha'=>$sha];
   }
  }return $proof;
 };
 $stage='exact physical native rename fallback';$fileProof=$physical();$checks['real_renames_reached_exact_fault_position']=true;
 $stage='exact native kernel failure log evidence';clearstatcache(true,$log);$logSize=filesize($log);
 if($logSize<$logStart||$logSize-$logStart>1048576)throw new RuntimeException('Native log evidence exceeds fixture bound.');
 $stream=fopen($log,'rb');if(!is_resource($stream)||fseek($stream,$logStart)!==0)throw new RuntimeException('Native log evidence unavailable.');
 try{$lines=stream_get_contents($stream,1048577);}finally{fclose($stream);}
 $rename=false;$unlink=false;
 foreach(explode("\n",$lines)as$line){if($line==='')continue;$row=json_decode($line,true);if(!is_array($row))continue;$message=$row['message']??'';
  if(str_starts_with($message,'failed to rename '.$local.' to ')&&str_contains($message,'trying copy+delete fallback instead'))$rename=true;
  if($message==='failed to delete '.$local.' as part of rename fallback')$unlink=true;
 }
 if(!$rename||!$unlink)throw new RuntimeException('Exact native OS rename and unlink failure evidence missing.');
 $checks['actual_native_rename_and_unlink_io_failures']=true;
 if($old->getContent()!=='original answer attachment'||$stray->getContent()!=='unmanaged retention canary')throw new RuntimeException('Original or unmanaged bytes changed.');
 $stage='fresh HTTP exact recovery discovery';$history=$call('forms_get_operation',['operation_id'=>$preview['operation_id']]);
 $private=$db->executeQuery('SELECT resource_refs FROM *PREFIX*mcp_operations WHERE actor_uid=? AND operation_id=?',[$uid,$preview['operation_id']])->fetchOne();
 $private=json_decode($private,true,64,JSON_THROW_ON_ERROR);$nativeKey=$private['native_key']??null;
 if(!is_string($nativeKey))throw new RuntimeException('Exact failed attempt native key unavailable.');
 $backup=$history['references']['backup_id']??null;if(($history['operation_id']??null)!==$preview['operation_id']||($history['state']??null)!=='partial'||!is_string($backup)||$backup!==hash('sha256','forms-recovery:'.$nativeKey))throw new RuntimeException('Fresh receipt did not discover exact legacy recovery.');
 $checks['fresh_http_recovery_discovery']=true;
 $repeated=$call($tool,$confirm);if(!in_array($repeated['operation']['state']??null,['partial','uncertain'],true)||!$sameSql()||$physical()!==$fileProof)throw new RuntimeException('Repeated confirmation replayed a file effect.');
 $checks['uncertain_effects_never_replayed']=true;
 $stage='public restore original response and staged bytes';$restored=$write('forms_restore',['backup_id'=>$backup]);$new=$restored['operation']['references']['form_id']??null;
 $stage='restored reference contract';
 if(!is_int($new)||$new===$id||!in_array($restored['operation']['state']??null,['succeeded','partial'],true))throw new RuntimeException('Restore did not create a new form.');
 $stage='restored native state';$native=$db->executeQuery('SELECT owner_id,state,access_enum,file_id,file_format FROM *PREFIX*forms_v2_forms WHERE id=?',[$new])->fetch();
 if($native['owner_id']!==$uid||(int)$native['state']!==1||(int)$native['access_enum']!==0||$native['file_id']!==null||$native['file_format']!==null||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_shares WHERE form_id=?',[$new])->fetchOne()!==0)throw new RuntimeException('Restore is not private closed and unlinked.');
 $stage='restored answer bindings';$rows=$db->executeQuery('SELECT a.*,s.user_id,s.timestamp,q.form_id AS question_form_id,q.type FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id JOIN *PREFIX*forms_v2_questions q ON q.id=a.question_id WHERE s.form_id=?',[$new])->fetchAll();
 if(count($rows)!==1||$rows[0]['user_id']!==$uid||(int)$rows[0]['timestamp']!==(int)$original['timestamp']||(int)$rows[0]['question_form_id']!==$new||$rows[0]['type']!=='file'||$rows[0]['text']!==$old->getName()||(int)$rows[0]['question_id']===$qid||(int)$rows[0]['submission_id']===$sid||(int)$rows[0]['file_id']===(int)$old->getId())throw new RuntimeException('Restored response bindings differ from original golden.');
 $copy=$home->getById((int)$rows[0]['file_id'])[0];if($copy->getContent()!=='original answer attachment')throw new RuntimeException('Original attachment recovery changed.');
 $stage='recovered complete byte set';$recoveredIds=array_map('intval',array_column($db->executeQuery('SELECT file_id FROM *PREFIX*forms_mcp_artifacts WHERE form_id=? ORDER BY file_id',[$new])->fetchAll(),'file_id'));$recovered=[];foreach($recoveredIds as$fileId){$node=$home->getById((int)$fileId)[0];$recovered[]=hash('sha256',$node->getContent());}
 $wanted=[hash('sha256','original answer attachment'),...$gold];sort($recovered);sort($wanted);
 if(count(array_unique($recoveredIds))!==3||$recovered!==$wanted||!$sameSql()||$physical()!==$fileProof||$stray->getContent()!=='unmanaged retention canary')throw new RuntimeException('Complete recovered byte set or retained original attempt changed.');
 $checks['all_original_and_staged_bytes_recovered']=true;$checks['new_private_closed_response_bindings']=true;
 echo json_encode(['ok'=>true,'position'=>$position,'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){echo json_encode(['ok'=>false,'stage'=>$stage,'error_class'=>get_class($e),'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";$exitCode=1;}
finally{if($triggerName!==null)$db->executeStatement('DROP TRIGGER '.$triggerName);}
exit($exitCode);
