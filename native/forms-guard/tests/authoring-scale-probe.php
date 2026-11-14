<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';
use OCP\Server;
try {
 Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
 $uid='guard-owner';Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));require '/tmp/origin-fixture.php';
 $g=Server::get(\OCA\Forms\Mcp\Guard::class);$api=Server::get(\OCA\Forms\Controller\ApiController::class);$db=Server::get(\OCP\IDBConnection::class);
 $id=$api->newForm()->getData()['id'];$q=$api->newQuestion($id,'dropdown',null,'Removed field')->getData()['id'];$api->newOption($id,$q,['First','Second']);$other=$api->newQuestion($id,'short',null,'Retained field')->getData()['id'];
 for($n=0;$n<101;$n++){$s=new \OCA\Forms\Db\Submission();$s->setFormId($id);$s->setUserId($uid);$s->setTimestamp(time());$s=Server::get(\OCA\Forms\Db\SubmissionMapper::class)->insert($s);foreach([$q,$other]as$qid){$a=new \OCA\Forms\Db\Answer();$a->setSubmissionId($s->getId());$a->setQuestionId($qid);$a->setText('preserved '.$n);Server::get(\OCA\Forms\Db\AnswerMapper::class)->insert($a);}}
 $target='authoring-'.bin2hex(random_bytes(5));Server::get(\OCP\IUserManager::class)->createUser($target,bin2hex(random_bytes(24)));
 $share=new \OCA\Forms\Db\Share();$share->setFormId($id);$share->setShareType(0);$share->setShareWith($target);$share->setPermissions(['submit','results']);$share=Server::get(\OCA\Forms\Db\ShareMapper::class)->insert($share);
 $checks=[];
 foreach(['forms_update'=>['id'=>$id,'fields'=>['title'=>'Scaled title']], 'forms_update_share'=>['id'=>$id,'share_id'=>$share->getId(),'permissions'=>['submit']], 'forms_unshare'=>['id'=>$id,'share_id'=>$share->getId()], 'forms_delete_question'=>['id'=>$id,'question_id'=>$q]]as$action=>$input){
  $input['_mcp_origin']=formsNativeOrigin($uid,$action,$input);
  try{if($action==='forms_delete_question'){$preview=$g->bulkSnapshot($uid,$action,$input);$checks[$action.'_preview']=true;$result=$g->bulkStart($uid,$action,$input,$preview['revision'],bin2hex(random_bytes(16)));$n=0;while($result['next']&&$n++<200){$result=$g->bulkStep($uid,$result['bulk_id'],$input['_mcp_origin'],$result['version'],bin2hex(random_bytes(16)));}$checks[$action.'_applied']=$result['state']==='completed';$backup=$result['references']['backup_id'];continue;}$preview=$g->snapshot($uid,$action,$input);$checks[$action.'_preview']=true;$result=$g->apply($uid,$action,$input,$preview['revision'],bin2hex(random_bytes(16)),$preview['file_manifest']);$checks[$action.'_applied']=($result['state']??null)==='succeeded';if($action==='forms_delete_question'){$backup=$result['references']['backup_id']??null;}}
  catch(Throwable $e){$checks[$action.'_preview']=false;$errors[$action]=$e->getMessage();}
 }
 $checks['unrelated_answers_retained']=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_answers WHERE question_id = ?',[$other])->fetchOne()===101;
 $checks['historical_options_retained']=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_options WHERE question_id = ?',[$q])->fetchOne()===2;$checks['historical_answers_retained']=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_answers WHERE question_id = ?',[$q])->fetchOne()===101;$checks['native_question_hidden']=Server::get(\OCA\Forms\Db\QuestionMapper::class)->findById($q)->getOrder()===0;
 if(isset($backup)){$i=['backup_id'=>$backup];$i['_mcp_origin']=formsNativeOrigin($uid,'forms_restore',$i);$p=$g->bulkSnapshot($uid,'forms_restore',$i);$r=$g->bulkStart($uid,'forms_restore',$i,$p['revision'],bin2hex(random_bytes(16)));$n=0;while($r['next']&&$n++<200){$r=$g->bulkStep($uid,$r['bulk_id'],$i['_mcp_origin'],$r['version'],bin2hex(random_bytes(16)));}$restored=$r['references']['restored_form_id'];$f=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($restored);$checks['restore_complete_options']=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_options o JOIN *PREFIX*forms_v2_questions q ON q.id=o.question_id WHERE q.form_id = ?',[$restored])->fetchOne()===2;$checks['restore_private_closed']=$f->getAccessEnum()===0&&$f->getState()===1;$checks['restore_complete_affected_answers']=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id = ?',[$restored])->fetchOne()===202;}
 echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks,'errors'=>$errors??[]],JSON_THROW_ON_ERROR);exit(in_array(false,$checks,true)?1:0);
}catch(Throwable$e){echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()]);exit(1);}
