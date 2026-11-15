<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')exit(2);
$case=getenv('MCP_FORMS_TRANSFER_CONTROL_CASE');if(!in_array($case,['cancel_before','cancel_staged','ack_first','ack_second'],true))exit(2);$fixtureCount=$case==='cancel_before'?1:3;
$trackedOrigin=dirname(__DIR__,5).'/native/forms-guard/tests/origin-fixture.php';
$originFixture=getenv('MCP_FORMS_NATIVE_ORIGIN_FIXTURE')?:(is_file($trackedOrigin)?$trackedOrigin:'/tmp/origin-fixture.php');
if(!is_file($originFixture)||is_link($originFixture))exit(2);
require_once $originFixture;if(!function_exists('formsNativeOrigin'))exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';use OCP\Server;
$stage='bootstrap';$checks=[];$exitCode=0;$evidence=null;$triggerName=null;
try {
 Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
 $db=Server::get(\OCP\IDBConnection::class);$users=Server::get(\OCP\IUserManager::class);$session=Server::get(\OCP\IUserSession::class);
 $uid='transfer-http-'.bin2hex(random_bytes(5));$expectedFile=getenv('MCP_FORMS_TRANSFER_HTTP_EXPECTED');
 if(!is_string($expectedFile)||preg_match('~^/tmp/forms-transfer-http-[a-f0-9]{16}\.json$~D',$expectedFile)!==1||is_link($expectedFile))throw new RuntimeException('An exact fresh disposable transfer evidence path is required.');
 $oldMask=umask(0077);try{$evidence=@fopen($expectedFile,'x+b');}finally{umask($oldMask);}$evidenceIdentity=is_resource($evidence)?fstat($evidence):false;if(!is_resource($evidence)||$evidenceIdentity===false||($evidenceIdentity['mode']&0777)!==0600||$evidenceIdentity['uid']!==posix_geteuid()||!flock($evidence,LOCK_EX|LOCK_NB))throw new RuntimeException('Cannot reserve exact private transfer evidence before effects.');$expected=['uid'=>$uid,'count'=>$fixtureCount,'target_uid'=>null,'form_id'=>null,'transfer_operation_id'=>null,'transfer_bulk_id'=>null,'latest'=>null];
 $saveExpected=static function()use($expectedFile,$evidence,$evidenceIdentity,&$expected,&$stage):void{
  clearstatcache(true,$expectedFile);$now=@lstat($expectedFile);if($now===false||is_link($expectedFile)||$now['dev']!==$evidenceIdentity['dev']||$now['ino']!==$evidenceIdentity['ino']||($now['mode']&0777)!==0600||$now['uid']!==$evidenceIdentity['uid'])throw new RuntimeException('Transfer evidence file identity changed.');$expected['stage']=$stage;$bytes=json_encode($expected,JSON_THROW_ON_ERROR);
  if(!rewind($evidence)||!ftruncate($evidence,0)||fwrite($evidence,$bytes)!==strlen($bytes)||!fflush($evidence))throw new RuntimeException('Transfer evidence persistence failed.');$actual=@file_get_contents($expectedFile);if(!is_string($actual)||!hash_equals($bytes,$actual))throw new RuntimeException('Transfer evidence readback differs.');
 };$saveExpected();$password=bin2hex(random_bytes(24));
 $owner=['uid'=>$uid,'password'=>$password,'user'=>$users->createUser($uid,$password)];$session->setUser($owner['user']);
 formsNativeOrigin($uid,'forms_capabilities');
 $rpc=static function(array $actor,string $name,array $args):array {
  $c=curl_init('http://127.0.0.1/index.php/apps/mcp/');curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_USERPWD=>$actor['uid'].':'.$actor['password'],CURLOPT_HTTPHEADER=>['Host: localhost','Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-06-18'],CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],JSON_THROW_ON_ERROR)]);
  $body=curl_exec($c);$http=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);$r=json_decode((string)$body,true);if($http!==200||!is_array($r))throw new RuntimeException('Recovery HTTP transport failed.');if(isset($r['error']))return ['isError'=>true];return $r['result'];
 };
 $actor=$owner;
 $call=static function(string $tool,array $args)use($rpc,&$actor):array {$r=$rpc($actor,$tool,$args);if($r['isError']??false)throw new RuntimeException('Refused '.$tool.': '.($r['content'][0]['text']??''));return $r['structuredContent']??json_decode($r['content'][0]['text'],true,64,JSON_THROW_ON_ERROR);};
 $deny=static function(string $tool,array $args)use($rpc,&$actor):void {if(!($rpc($actor,$tool,$args)['isError']??false))throw new RuntimeException('Forbidden recovery reader admitted: '.$tool);};
 $write=static function(string $tool,array $args)use($call):array {$p=$call($tool,$args);return $call($tool,$args+['confirm'=>true,'confirm_shared'=>$p['requiresSharedConfirmation']??false,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]);};


 $complete=static function(string $tool,array $args)use($write,$call,&$expected,$saveExpected):array{
  $r=$write($tool,$args);$original=$r['operation']['operation_id'];$expected['restore_operation_id']=$original;$expected['restore_bulk_id']=$r['operation']['references']['bulk_id']??null;$saveExpected();
  for($i=0;($r['operation']['state']??null)==='pending'&&$i<900;$i++){$r=$write('forms_continue_operation',['target_operation_id'=>$original]);$expected['latest']=['operation_id'=>$r['operation']['operation_id']??null,'state'=>$r['operation']['state']??null,'phase'=>$r['data']['phase']??null,'version'=>$r['data']['version']??null,'restored_form_id'=>$r['operation']['references']['restored_form_id']??null];$saveExpected();}
  $refs=$r['operation']['references']??[];
  if(!($r['data']['complete']??false)||!in_array($r['operation']['state']??null,['succeeded','partial'],true)||($refs['database']??null)!=='committed'||!in_array($refs['files']??null,['verified','not_applicable'],true))throw new RuntimeException('Unverified terminal lifecycle.');
  if(($r['operation']['state']??null)==='partial'){if(($refs['notifications']??[])===[])throw new RuntimeException('Partial without explicit event evidence.');foreach($refs['notifications']as$event)if(($event['phase']??null)!=='attempted_unverified')throw new RuntimeException('Unknown partial event.');}
  $fresh=$call('forms_get_operation',['operation_id'=>$r['operation']['operation_id']]);
  if(($fresh['operation_id']??null)!==$r['operation']['operation_id']||($fresh['state']??null)!==$r['operation']['state']||\OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal::canonical($fresh['references']??[])!==\OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal::canonical($refs))throw new RuntimeException('Exact terminal HTTP receipt differs.');
  return $r;
 };

 $mode=$case;$count=$fixtureCount;
 $stage='native transfer golden source';$api=Server::get(\OCA\Forms\Controller\ApiController::class);$id=$api->newForm()->getData()['id'];$expected['form_id']=$id;$saveExpected();
 $fileQuestion=$api->newQuestion($id,'file',null,'Exact transferred file')->getData()['id'];$textQuestion=$api->newQuestion($id,'short',null,'Exact transferred text')->getData()['id'];
 $form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);$home=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$formPath=Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form);
 $folder=$home->newFolder($formPath);$stray=$folder->newFile('unmanaged-transfer-retained.txt','unmanaged transfer canary');$source=[];
 \OCA\Forms\Mcp\Service\FormsRevisionFence::run(function()use($id,$fileQuestion,$textQuestion,$uid,$folder,$db,$count,&$source):void{
  for($i=0;$i<$count;$i++){
   $name='transfer-file-'.$i.'.txt';$bytes='exact transferred bytes '.$uid.' '.$i;$file=$folder->newFile($name,$bytes);$source[$i]=['id'=>(int)$file->getId(),'path'=>$file->getPath(),'etag'=>$file->getEtag(),'sha'=>hash('sha256',$bytes),'name'=>$name];
   $s=new \OCA\Forms\Db\Submission();$s->setFormId($id);$s->setUserId($uid);$s->setTimestamp(1794614400+$i);$s=Server::get(\OCA\Forms\Db\SubmissionMapper::class)->insert($s);
   foreach([[$fileQuestion,$name,(int)$file->getId()],[$textQuestion,'transfer text '.$i,null]]as[$qid,$value,$fid]){$a=new \OCA\Forms\Db\Answer();$a->setSubmissionId($s->getId());$a->setQuestionId($qid);$a->setFileId($fid);$a->setText($value);Server::get(\OCA\Forms\Db\AnswerMapper::class)->insert($a);}
   $db->executeStatement('INSERT INTO *PREFIX*forms_mcp_artifacts (form_id,file_id) VALUES (?,?)',[$id,$file->getId()]);
  }
 });
 $answers=static fn():array=>$db->executeQuery('SELECT a.* FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id=? ORDER BY a.id',[$id])->fetchAll();
 $goldQuestions=$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_questions WHERE form_id=? ORDER BY id',[$id])->fetchAll();
 $questionMap=static function(array $gold,array $restored,int $newForm):array {
  if(count($gold)!==count($restored))throw new RuntimeException('Recovered exact question metadata differs.');$map=[];$used=[];
  foreach($gold as$old){$matches=[];foreach($restored as$row){$before=$old;$after=$row;unset($before['id'],$before['form_id'],$after['id'],$after['form_id']);if($before===$after&&(int)$row['form_id']===$newForm)$matches[]=$row;}
   if(count($matches)!==1||isset($used[(int)$matches[0]['id']]))throw new RuntimeException('Recovered exact question metadata differs.');$newId=(int)$matches[0]['id'];if($newId<1||$newId===(int)$old['id'])throw new RuntimeException('Recovered question identity was not distinct.');$map[(int)$old['id']]=$newId;$used[$newId]=true;
  }return$map;
 };
 $goldAnswers=$answers();$goldSubmissions=$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_submissions WHERE form_id=? ORDER BY id',[$id])->fetchAll();$goldArtifacts=array_map('intval',array_column($db->executeQuery('SELECT file_id FROM *PREFIX*forms_mcp_artifacts WHERE form_id=? ORDER BY file_id',[$id])->fetchAll(),'file_id'));
 $targetUid='transfer-target-'.bin2hex(random_bytes(5));$expected['target_uid']=$targetUid;$saveExpected();$targetPassword=bin2hex(random_bytes(24));$target=['uid'=>$targetUid,'password'=>$targetPassword,'user'=>$users->createUser($targetUid,$targetPassword)];$targetHome=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($targetUid);$targetHome->newFolder('Forms');
 $assertOriginal=static function()use($db,$id,$uid,$answers,$goldAnswers,$goldSubmissions,$goldArtifacts):void{
  if($db->executeQuery('SELECT owner_id FROM *PREFIX*forms_v2_forms WHERE id=?',[$id])->fetchOne()!==$uid||$answers()!==$goldAnswers||$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_submissions WHERE form_id=? ORDER BY id',[$id])->fetchAll()!==$goldSubmissions||array_map('intval',array_column($db->executeQuery('SELECT file_id FROM *PREFIX*forms_mcp_artifacts WHERE form_id=? ORDER BY file_id',[$id])->fetchAll(),'file_id'))!==$goldArtifacts)throw new RuntimeException('Source owner or exact answer/artifact preimages changed.');
 };
 $assertSourceBytes=static function()use($home,$source,$stray):void{
  foreach($source as$row){$file=$home->getById($row['id'])[0]??null;if(!$file instanceof \OCP\Files\File||(int)$file->getId()!==$row['id']||$file->getPath()!==$row['path']||$file->getEtag()!==$row['etag']||hash('sha256',$file->getContent())!==$row['sha'])throw new RuntimeException('Exact original bytes/ID/version changed.');}if($stray->getContent()!=='unmanaged transfer canary')throw new RuntimeException('Unmanaged source bytes changed.');
 };

 $stage='exact public transfer control admission';$args=['id'=>$id,'owner_id'=>$targetUid];$r=$write('forms_transfer',$args);$original=$r['operation']['operation_id'];$bulkId=$r['operation']['references']['bulk_id']??null;if(!is_string($bulkId)||preg_match('/^[a-f0-9]{64}$/D',$bulkId)!==1||($r['operation']['state']??null)!=='pending')throw new RuntimeException('Exact pending native transfer unavailable.');$expected['transfer_operation_id']=$original;$expected['transfer_bulk_id']=$bulkId;$saveExpected();$store=Server::get(\OCA\Forms\Mcp\Service\BulkStore::class);$assertOriginal();
 $step=static function()use($write,$original,&$expected,$saveExpected,&$r,$assertOriginal):array{$assertOriginal();$r=$write('forms_continue_operation',['target_operation_id'=>$original]);$expected['latest']=['operation_id'=>$r['operation']['operation_id']??null,'state'=>$r['operation']['state']??null,'phase'=>$r['data']['phase']??null,'version'=>$r['data']['version']??null,'retained'=>$r['data']['retained_files']??null];$saveExpected();return$r;};
 $before=$case==='cancel_before';$ack=str_starts_with($case,'ack_');$position=$case==='ack_second'?2:1;
 $stage=$before?'cancel before staging':'advance exact transfer to staged-file boundary';
 if(!$before){$limit=$ack?$position-1:1;for($n=0;$n<150;$n++){$j=$store->load($bulkId);$copies=(int)($j['retained_files']['copies']??0);if(($ack?$j['phase']==='copy_files':true)&&$copies===$limit&&$j['sealed'])break;if($j['state']!=='pending'||$copies>$limit)throw new RuntimeException('Exact staging boundary changed.');$step();}$j=$store->load($bulkId);if(!$j['sealed']||($ack&&$j['phase']!=='copy_files')||(int)($j['retained_files']['copies']??0)!==$limit)throw new RuntimeException('Exact staging boundary not reached.');}
 $physical=static function(int $expectedCopies)use($targetHome,$formPath,$bulkId,$source):array{
  $storage=$targetHome->getStorage();$proof=[];
  foreach($source as$i=>$row){$path=trim($targetHome->getInternalPath(),'/').'/'.$formPath.'/bulk-'.$bulkId.'/source/'.$formPath.'/'.$row['name'];$exists=$storage->file_exists($path);if($exists!==($i<$expectedCopies))throw new RuntimeException('Exact physical copy position differs.');$p=['path'=>$path,'exists'=>$exists];if($exists){$stream=$storage->fopen($path,'rb');if(!is_resource($stream))throw new RuntimeException('Physical staged copy unreadable.');try{$h=hash_init('sha256');hash_update_stream($h,$stream);$p['sha']=hash_final($h);}finally{fclose($stream);}if($p['sha']!==$row['sha'])throw new RuntimeException('Physical staged copy bytes differ.');$st=$storage->stat($path);$p['stat']=array_intersect_key($st,array_flip(['mtime','ctime','size']));}$proof[]=$p;
  }return$proof;
 };
 if($before)$step(); // Positive continuation control remains before any Files staging.
 if($ack){
  $stage='actual target copy followed by exact mapping receipt fault';$j=$store->load($bulkId);$sourceId=$source[$position-1]['id'];$triggerName='forms_transfer_ack_'.bin2hex(random_bytes(5));
  $db->executeStatement('CREATE TRIGGER '.$triggerName.' BEFORE UPDATE ON *PREFIX*forms_mcp_bulk_rows FOR EACH ROW BEGIN IF NEW.bulk_id = \''.$bulkId.'\' AND NEW.kind = \'files\' AND NEW.source_id = '.$sourceId.' AND OLD.mapped_id = 0 AND NEW.mapped_id > 0 THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Disposable exact transfer mapping acknowledgement failure\'; END IF; END');
  try{$failedPreview=$call('forms_continue_operation',['target_operation_id'=>$original]);$failedConfirm=['target_operation_id'=>$original,'confirm'=>true,'confirm_shared'=>$failedPreview['requiresSharedConfirmation']??false,'plan_state'=>$failedPreview['plan_state'],'operation_id'=>$failedPreview['operation_id']];$terminal=$call('forms_continue_operation',$failedConfirm);}finally{$db->executeStatement('DROP TRIGGER IF EXISTS '.$triggerName);$triggerName=null;}
  $native=$store->load($bulkId);if($native['state']!=='uncertain'||($native['claim']['file_id']??null)!==$sourceId||($native['claim']['phase']??null)!=='copy_files'||(int)($native['retained_files']['copies']??0)!==$position-1||($terminal['operation']['state']??null)!=='uncertain'||($terminal['operation']['references']['files']??null)!=='unknown')throw new RuntimeException('Copy acknowledgement loss was not honest uncertainty.');
  $physicalProof=$physical($position);$sourceMap=$db->executeQuery('SELECT source_id,mapped_id FROM *PREFIX*forms_mcp_bulk_rows WHERE bulk_id=? AND kind=? ORDER BY source_id',[$bulkId,'files'])->fetchAll();foreach($sourceMap as$i=>$map)if(((int)$map['mapped_id']>0)!==($i<$position-1))throw new RuntimeException('Faulted copied mapping was falsely persisted.');
  $checks['actual_copy_then_mapping_receipt_failure']=true;$checks['honest_cancel_or_uncertain_state']=true;
 }else{
  $stage=$before?'public cancellation before any Files staging':'public cancellation after exactly one staged copy';$physicalProof=$physical($before?0:1);$p=$call('forms_cancel_operation',['target_operation_id'=>$original]);$cancelConfirm=['target_operation_id'=>$original,'confirm'=>true,'confirm_shared'=>$p['requiresSharedConfirmation']??false,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];$terminal=$call('forms_cancel_operation',$cancelConfirm);$native=$store->load($bulkId);
  if(($terminal['data']['state']??null)!==($before?'cancelled':'partial')||($terminal['operation']['state']??null)!==($before?'succeeded':'partial')||($terminal['data']['complete']??null)!==false||$native['state']!==($before?'cancelled':'partial')||$native['done']!==0||($native['source_effects']??false)||isset($native['claim']))throw new RuntimeException('Cancellation misrepresented native source effects.');
  if($before){if($native['sealed']||array_sum($native['retained_files']??[])!==0||$targetHome->nodeExists($formPath))throw new RuntimeException('Early cancellation left target or recovery Files effects.');$checks['early_cancel_has_no_target_or_recovery_bytes']=true;}
  else{if(!$native['sealed']||(int)($native['retained_files']['copies']??0)!==1||(int)($native['retained_files']['recovery']??0)!==$count)throw new RuntimeException('Staged cancellation lost exact retained copies/recovery.');$checks['staged_cancel_retains_one_copy_and_complete_backup']=true;}
  $checks['honest_cancel_or_uncertain_state']=true;
 }
 $assertOriginal();$assertSourceBytes();$checks['exact_original_rows_and_bytes_retained']=true;
 $stage='fresh same-attempt control and recovery evidence';$terminalId=$terminal['operation']['operation_id'];$fresh=$call('forms_get_operation',['operation_id'=>$terminalId]);$backup=$before?null:'bulk_v1:'.$bulkId;
 if(($fresh['operation_id']??null)!==$terminalId||($fresh['references']['bulk_id']??null)!==$bulkId||($fresh['references']['backup_id']??null)!==$backup||(! $before&&!in_array($fresh['state']??null,['partial','uncertain'],true)))throw new RuntimeException('Fresh exact control lost its real recovery evidence.');$checks['fresh_same_operation_recovery_evidence']=true;
 $stage='exact confirmation and new continuation never repeat File effects';$beforeReplay=$store->load($bulkId);$call($ack?'forms_continue_operation':'forms_cancel_operation',$ack?$failedConfirm:$cancelConfirm);
 $attempt=$rpc($owner,'forms_continue_operation',['target_operation_id'=>$original]);if(!($attempt['isError']??false)){ $structured=$attempt['structuredContent']??json_decode($attempt['content'][0]['text'],true,64,JSON_THROW_ON_ERROR);if(isset($structured['plan_state'],$structured['operation_id']))$rpc($owner,'forms_continue_operation',['target_operation_id'=>$original,'confirm'=>true,'confirm_shared'=>$structured['requiresSharedConfirmation']??false,'plan_state'=>$structured['plan_state'],'operation_id'=>$structured['operation_id']]); }
 if($store->load($bulkId)!==$beforeReplay||$physical($before?0:($ack?$position:1))!==$physicalProof)throw new RuntimeException('A terminal or uncertain copy intent replayed.');$assertOriginal();$assertSourceBytes();$checks['exact_confirmation_and_new_continue_do_not_replay']=true;
 if($before){echo json_encode(['ok'=>true,'checks'=>$checks,'mode'=>$mode,'position'=>null],JSON_THROW_ON_ERROR).PHP_EOL;}
 else{
 $stage='public exact sealed pre-transfer recovery';$restored=$complete('forms_restore',['backup_id'=>$backup]);$new=$restored['operation']['references']['restored_form_id']??null;$expected['restored_form_id']=$new;$expected['restore_bulk_id']=$restored['operation']['references']['bulk_id']??null;$saveExpected();if(!is_int($new)||$new===$id)throw new RuntimeException('No distinct recovered form.');
 $f=$db->executeQuery('SELECT owner_id,state,access_enum,file_id,file_format FROM *PREFIX*forms_v2_forms WHERE id=?',[$new])->fetch();if(!$f||$f['owner_id']!==$uid||(int)$f['state']!==1||(int)$f['access_enum']!==0||$f['file_id']!==null||$f['file_format']!==null||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_shares WHERE form_id=?',[$new])->fetchOne()!==0)throw new RuntimeException('Recovered form is not exact private closed/unlinked/no-shares.');
 $recoveredQuestions=$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_questions WHERE form_id=? ORDER BY id',[$new])->fetchAll();$questionMapping=$questionMap($goldQuestions,$recoveredQuestions,$new);
 foreach($recoveredQuestions as$index=>$question){$changed=$recoveredQuestions;$changed[$index]['text'].=' altered';$denied=false;try{$questionMap($goldQuestions,$changed,$new);}catch(RuntimeException $e){$denied=$e->getMessage()==='Recovered exact question metadata differs.';}if(!$denied)throw new RuntimeException('Changed recovered question text escaped the golden oracle.');}
 if((int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_linked WHERE form_id=?',[$new])->fetchOne()!==0||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*mcp_forms_jobs WHERE actor_uid=?',[$uid])->fetchOne()!==0)throw new RuntimeException('Recovered form inherited linked or result jobs.');
 $rows=$db->executeQuery('SELECT a.file_id,a.text,a.submission_id,q.id AS question_id,q.type,q.form_id AS question_form_id,s.user_id,s.timestamp FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id JOIN *PREFIX*forms_v2_questions q ON q.id=a.question_id WHERE s.form_id=? ORDER BY s.timestamp,q.type',[$new])->fetchAll();if(count($rows)!==$count*2||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_submissions WHERE form_id=?',[$new])->fetchOne()!==$count||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_questions WHERE form_id=?',[$new])->fetchOne()!==2)throw new RuntimeException('Recovered rows are truncated or unexpected.');
 $seen=[];$newFiles=[];$questionIds=[];foreach($rows as$row){$i=(int)$row['timestamp']-1794614400;if($i<0||$i>=$count||(int)$row['question_id']!==($questionMapping[$row['type']==='file'?$fileQuestion:$textQuestion]??null)||$row['user_id']!==$uid||(int)$row['question_form_id']!==$new||isset($seen[$i][$row['type']]))throw new RuntimeException('Recovered exact submission/question provenance differs.');$seen[$i][$row['type']]=(int)$row['submission_id'];$questionIds[$row['type']][(int)$row['question_id']]=true;
  if($row['type']==='file'){$fid=(int)$row['file_id'];$file=$home->getById($fid)[0]??null;if($row['text']!==$source[$i]['name']||$fid<1||in_array($fid,$goldArtifacts,true)||isset($newFiles[$fid])||!$file instanceof \OCP\Files\File||$file->getOwner()?->getUID()!==$uid||hash('sha256',$file->getContent())!==$source[$i]['sha'])throw new RuntimeException('Recovered exact file bytes/binding differ.');$newFiles[$fid]=true;}
  elseif($row['type']==='short'){if($row['text']!=='transfer text '.$i||$row['file_id']!==null)throw new RuntimeException('Recovered exact text differs.');}else throw new RuntimeException('Unexpected recovered question type.');
 }
 if(count($seen)!==$count||count($newFiles)!==$count||count($questionIds['file']??[])!==1||count($questionIds['short']??[])!==1)throw new RuntimeException('Recovered distinct rows/files/questions differ.');foreach($seen as$parts)if(count($parts)!==2||count(array_unique($parts))!==1)throw new RuntimeException('Recovered answers mixed submissions.');
 $artifacts=array_map('intval',array_column($db->executeQuery('SELECT file_id FROM *PREFIX*forms_mcp_artifacts WHERE form_id=? ORDER BY file_id',[$new])->fetchAll(),'file_id'));$expectedFiles=array_keys($newFiles);sort($expectedFiles,SORT_NUMERIC);if($artifacts!==$expectedFiles)throw new RuntimeException('Recovered exact managed artifact set differs.');$assertSourceBytes();$checks['all_original_bytes_and_exact_private_recovery']=true;
 echo json_encode(['ok'=>true,'checks'=>$checks,'position'=>$ack?$position:null,'mode'=>$mode],JSON_THROW_ON_ERROR)."\n";

 }

}catch(Throwable $e){echo json_encode(['ok'=>false,'stage'=>$stage,'error_class'=>get_class($e),'checks'=>$checks],JSON_THROW_ON_ERROR).PHP_EOL;$exitCode=1;}
finally{if($triggerName!==null)$db->executeStatement('DROP TRIGGER IF EXISTS '.$triggerName);if(is_resource($evidence)){flock($evidence,LOCK_UN);fclose($evidence);}}
exit($exitCode);
