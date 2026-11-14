<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';use OCP\Server;
$stage='bootstrap';$checks=[];$exitCode=0;$global=null;$config=null;$triggerName=null;
try {
 Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
 $db=Server::get(\OCP\IDBConnection::class);$users=Server::get(\OCP\IUserManager::class);$session=Server::get(\OCP\IUserSession::class);$config=Server::get(\OCP\IAppConfig::class);
 $global=$config->getValueBool('forms','allowComments',false);$config->setValueBool('forms','allowComments',true);
 $nonce=bin2hex(random_bytes(5));$actors=[];foreach(['owner','outsider'] as $role){$uid='recovery-http-'.$nonce.'-'.$role;$pw=bin2hex(random_bytes(24));$actors[$role]=['uid'=>$uid,'password'=>$pw,'user'=>$users->createUser($uid,$pw)];}
 $owner=$actors['owner'];$outsider=$actors['outsider'];$uid=$owner['uid'];$session->setUser($owner['user']);require '/tmp/origin-fixture.php';
 formsNativeOrigin($uid,'forms_capabilities');$session->setUser($outsider['user']);formsNativeOrigin($outsider['uid'],'forms_capabilities');$session->setUser($owner['user']);
 $rpc=static function(array $actor,string $name,array $args):array {
  $c=curl_init('http://127.0.0.1/index.php/apps/mcp/');curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_USERPWD=>$actor['uid'].':'.$actor['password'],CURLOPT_HTTPHEADER=>['Host: localhost','Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-06-18'],CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],JSON_THROW_ON_ERROR)]);
  $body=curl_exec($c);$http=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);$r=json_decode((string)$body,true);if($http!==200||!is_array($r))throw new RuntimeException('Recovery HTTP transport failed.');if(isset($r['error']))return ['isError'=>true];return $r['result'];
 };
 $call=static function(string $tool,array $args,?array $actor=null)use($rpc,$owner):array {$r=$rpc($actor??$owner,$tool,$args);if($r['isError']??false)throw new RuntimeException('Refused '.$tool.': '.($r['content'][0]['text']??''));return $r['structuredContent']??json_decode($r['content'][0]['text'],true,64,JSON_THROW_ON_ERROR);};
 $deny=static function(string $tool,array $args,?array $actor=null)use($rpc,$owner):void {if(!($rpc($actor??$owner,$tool,$args)['isError']??false))throw new RuntimeException('Forbidden recovery reader admitted: '.$tool);};
 $write=static function(string $tool,array $args)use($call):array {$p=$call($tool,$args);return $call($tool,$args+['confirm'=>true,'confirm_shared'=>$p['requiresSharedConfirmation']??false,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]);};
 $drive=static function(string $original)use($write):array {for($i=0;$i<80;$i++){$r=$write('forms_continue_operation',['target_operation_id'=>$original]);if(($r['operation']['state']??null)==='succeeded'&&($r['data']['complete']??false))return $r;if(($r['operation']['state']??null)!=='pending')throw new RuntimeException('Unexpected bounded recovery state.');}throw new RuntimeException('Recovery fixture step budget exhausted.');};
 $position=(int)(getenv('MCP_FORMS_FILE_FAULT_POSITION')?:'1');if(!in_array($position,[1,2],true))throw new RuntimeException('Choose exact file fault position1or2.');
 $stage='native managed files setup';$api=Server::get(\OCA\Forms\Controller\ApiController::class);$id=$api->newForm()->getData()['id'];$qid=$api->newQuestion($id,'file',null,'Recover exact bytes')->getData()['id'];$form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);$home=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$folder=$home->newFolder(Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form));$stray=$folder->newFile('unmanaged-keep.txt','retain uncontrolled bytes');$files=[];$expected=[];$sourceFiles=[];
 \OCA\Forms\Mcp\Service\FormsRevisionFence::run(function()use($id,$qid,$uid,$folder,$db,&$files,&$expected,&$sourceFiles):void {for($i=0;$i<2;$i++){$name='recover-'.$i.'.txt';$content='original bytes '.$i;$file=$folder->newFile($name,$content);$files[]=$file;$expected[$name]=hash('sha256',$content);$s=new \OCA\Forms\Db\Submission();$s->setFormId($id);$s->setUserId($uid);$s->setTimestamp(time());$s=Server::get(\OCA\Forms\Db\SubmissionMapper::class)->insert($s);$sourceFiles[$name]=['old_file_id'=>(int)$file->getId(),'submission_id'=>(int)$s->getId(),'sha256'=>$expected[$name]];$a=new \OCA\Forms\Db\Answer();$a->setSubmissionId($s->getId());$a->setQuestionId($qid);$a->setFileId($file->getId());$a->setText($name);Server::get(\OCA\Forms\Db\AnswerMapper::class)->insert($a);$db->executeStatement('INSERT INTO *PREFIX*forms_mcp_artifacts (form_id,file_id) VALUES (?,?)',[$id,$file->getId()]);}});
 $stage='public delete admission';$started=$write('forms_delete_form',['id'=>$id]);$original=$started['operation']['operation_id'];$bulk=$started['operation']['references']['bulk_id'];$store=Server::get(\OCA\Forms\Mcp\Service\BulkStore::class);$target=(int)$files[$position-1]->getId();$failed=null;$confirmation=null;
 for($i=0;$i<75;$i++){
  $j=$store->load($bulk);$rows=$j['phase']==='delete_files'?$store->page($bulk,'files',$j['cursor']):[];
  if($rows!==[]&&$rows[0]['id']===$target){
   if(!$j['sealed'])throw new RuntimeException('Exact file receipt fault lacks sealed recovery.');
   $stage='exact after-file-effect database fault';$triggerName='forms_file_fault_'.bin2hex(random_bytes(5));$trigger=$triggerName;$table='*PREFIX*forms_mcp_bulk_steps';
   $db->executeStatement('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$table.' FOR EACH ROW BEGIN IF NEW.bulk_id = '.$db->quote($bulk)." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Disposable exact Forms file acknowledgement failure'; END IF; END");
   try{$args=['target_operation_id'=>$original];$p=$call('forms_continue_operation',$args);$confirmation=$args+['confirm'=>true,'confirm_shared'=>$p['requiresSharedConfirmation']??false,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];$failed=$call('forms_continue_operation',$confirmation);}
   finally{$db->executeStatement('DROP TRIGGER '.$trigger);$triggerName=null;}
   break;
  }
  $r=$write('forms_continue_operation',['target_operation_id'=>$original]);if(($r['operation']['state']??null)!=='pending')throw new RuntimeException('Source did not remain pending before injected file fault.');
 }
 if($failed===null||($failed['operation']['state']??null)!=='uncertain')throw new RuntimeException('Actual file fault did not preserve an uncertain operation.');
 $j=$store->load($bulk);$backup=$failed['operation']['references']['backup_id']??null;$nativeVersion=$j['version'];
 if($j['state']!=='uncertain'||!$j['sealed']||!is_string($backup)||$backup!=='bulk_v1:'.$bulk||($j['claim']['file_id']??null)!==$target)throw new RuntimeException('Fault lost exact durable file claim or sealed recovery.');
 foreach($files as $i=>$file){$exists=$file->getStorage()->file_exists($file->getInternalPath());if($exists!==($i>=$position))throw new RuntimeException('Fault did not occur at exact requested file position.');}
 if($db->executeQuery('SELECT id FROM *PREFIX*forms_v2_forms WHERE id = ?',[$id])->fetchOne()===false||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_submissions WHERE form_id = ?',[$id])->fetchOne()!==2||$stray->getContent()!=='retain uncontrolled bytes')throw new RuntimeException('SQL rollback or unmanaged-file isolation failed.');$checks['actual_file_deletion_then_receipt_persistence_failure']=true;
 $stage='fresh HTTP evidence and no replay';$history=$call('forms_get_operation',['operation_id'=>$failed['operation']['operation_id']]);
 if(($history['state']??null)!=='uncertain'||($history['references']['backup_id']??null)!==$backup)throw new RuntimeException('Fresh HTTP history lost uncertain recovery evidence.');$checks['fresh_http_backup_discovery']=true;
 $repeated=$call('forms_continue_operation',$confirmation);if(($repeated['operation']['state']??null)!=='uncertain'||$store->load($bulk)['version']!==$nativeVersion)throw new RuntimeException('Uncertain confirmation repeated a file effect.');
 $deny('forms_continue_operation',['target_operation_id'=>$original]);$checks['uncertain_never_replayed']=true;
 $stage='public recovery of all bytes';$restoring=$write('forms_restore',['backup_id'=>$backup]);$restored=$drive($restoring['operation']['operation_id']);$new=$restored['operation']['references']['restored_form_id']??throw new RuntimeException('Restoration did not produce a new form.');
 $native=$db->executeQuery('SELECT owner_id,state,access_enum,file_id,file_format FROM *PREFIX*forms_v2_forms WHERE id = ?',[$new])->fetch();
 if($new===$id||$native['owner_id']!==$uid||(int)$native['state']!==1||(int)$native['access_enum']!==0||$native['file_id']!==null||$native['file_format']!==null||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_shares WHERE form_id = ?',[$new])->fetchOne()!==0)throw new RuntimeException('Recovery is not new private closed and unlinked.');
 $oracleExpected=['uid'=>$uid,'source_native_id'=>$bulk,'restore_native_id'=>$restored['operation']['references']['bulk_id'],
  'source_form_id'=>$id,'restored_form_id'=>$new,'source_question_id'=>$qid,'files'=>$sourceFiles];
 require getenv('MCP_FORMS_FILE_RECOVERY_ORACLE')?:__DIR__.'/bulk-file-recovery-oracle.php';
 if(!formsBulkRecoveryOracle($oracleExpected))throw new RuntimeException('Restored file set or original question/submission relationships differ.');
 if(in_array(false,formsBulkRecoveryMutationProof($oracleExpected),true))throw new RuntimeException('Recovery oracle accepted duplicated or foreign bindings.');
 $checks['restoration_oracle_rejects_wrong_bindings']=true;
 if($stray->getContent()!=='retain uncontrolled bytes'||$store->load($bulk)['version']!==$nativeVersion)throw new RuntimeException('Restoration altered unrelated file or replayed source job.');$checks['all_recovery_bytes_and_answer_mappings']=true;$checks['private_closed_restore_preserves_original_claim']=true;
 echo json_encode(['ok'=>true,'position'=>$position,'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$e->getMessage(),'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";$exitCode=1;}
finally{if($triggerName!==null)$db->executeStatement('DROP TRIGGER '.$triggerName);if($config!==null&&$global!==null)$config->setValueBool('forms','allowComments',$global);}
exit($exitCode);
