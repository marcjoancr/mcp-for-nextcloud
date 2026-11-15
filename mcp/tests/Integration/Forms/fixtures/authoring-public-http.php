<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')exit(2);
$originFixture=getenv('MCP_FORMS_NATIVE_ORIGIN_FIXTURE')?:dirname(__DIR__,5).'/native/forms-guard/tests/origin-fixture.php';
if(!is_file($originFixture)||is_link($originFixture)){fwrite(STDERR,"Stage the tracked native/forms-guard/tests/origin-fixture.php and set MCP_FORMS_NATIVE_ORIGIN_FIXTURE to its path.\n");exit(2);}
require_once $originFixture;
if(!function_exists('formsNativeOrigin')){fwrite(STDERR,"Invalid native Forms policy fixture.\n");exit(2);}
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';use OCP\Server;
$stage='bootstrap';$checks=[];$exitCode=0;$global=null;$config=null;
try {
 Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
 $db=Server::get(\OCP\IDBConnection::class);$users=Server::get(\OCP\IUserManager::class);$session=Server::get(\OCP\IUserSession::class);$config=Server::get(\OCP\IAppConfig::class);
 $global=$config->getValueBool('forms','allowComments',false);$config->setValueBool('forms','allowComments',true);
 $nonce=bin2hex(random_bytes(5));$actors=[];foreach(['owner','outsider'] as $role){$uid='recovery-http-'.$nonce.'-'.$role;$pw=bin2hex(random_bytes(24));$actors[$role]=['uid'=>$uid,'password'=>$pw,'user'=>$users->createUser($uid,$pw)];}
 $owner=$actors['owner'];$outsider=$actors['outsider'];$uid=$owner['uid'];$session->setUser($owner['user']);
 formsNativeOrigin($uid,'forms_capabilities');$session->setUser($outsider['user']);formsNativeOrigin($outsider['uid'],'forms_capabilities');$session->setUser($owner['user']);
 $rpc=static function(array $actor,string $name,array $args):array {
  $c=curl_init('http://127.0.0.1/index.php/apps/mcp/');curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_USERPWD=>$actor['uid'].':'.$actor['password'],CURLOPT_HTTPHEADER=>['Host: localhost','Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-06-18'],CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>$name==='__list'?'tools/list':'tools/call','params'=>$name==='__list'?(object)[]:['name'=>$name,'arguments'=>$args===[]?(object)[]:$args]],JSON_THROW_ON_ERROR)]);
  $body=curl_exec($c);$http=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);$r=json_decode((string)$body,true);if($http!==200||!is_array($r))throw new RuntimeException('Recovery HTTP transport failed.');if(isset($r['error']))return ['isError'=>true];return $r['result'];
 };
 $call=static function(string $tool,array $args,?array $actor=null)use($rpc,$owner):array {$r=$rpc($actor??$owner,$tool,$args);if($r['isError']??false)throw new RuntimeException('Refused '.$tool.': '.($r['content'][0]['text']??''));return $r['structuredContent']??json_decode($r['content'][0]['text'],true,64,JSON_THROW_ON_ERROR);};
 $deny=static function(string $tool,array $args,?array $actor=null)use($rpc,$owner):void {if(!($rpc($actor??$owner,$tool,$args)['isError']??false))throw new RuntimeException('Forbidden recovery reader admitted: '.$tool);};
 $write=static function(string $tool,array $args)use($call):array {$p=$call($tool,$args);return $call($tool,$args+['confirm'=>true,'confirm_shared'=>$p['requiresSharedConfirmation']??false,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]);};


 $stage='populated native form setup';$api=Server::get(\OCA\Forms\Controller\ApiController::class);$id=$api->newForm()->getData()['id'];$q=$api->newQuestion($id,'dropdown',null,'Historical choice')->getData()['id'];$api->newOption($id,$q,['First','Second']);$other=$api->newQuestion($id,'short',null,'Retained text')->getData()['id'];
 $sm=Server::get(\OCA\Forms\Db\SubmissionMapper::class);$am=Server::get(\OCA\Forms\Db\AnswerMapper::class);
 \OCA\Forms\Mcp\Service\FormsRevisionFence::run(function()use($sm,$am,$id,$q,$other,$uid):void{for($n=0;$n<101;$n++){$s=new \OCA\Forms\Db\Submission();$s->setFormId($id);$s->setUserId($uid);$s->setTimestamp(1794614400+$n);$s=$sm->insert($s);foreach([$q,$other] as $qid){$a=new \OCA\Forms\Db\Answer();$a->setSubmissionId($s->getId());$a->setQuestionId($qid);$a->setText('exact '.$qid.' response '.$n);$am->insert($a);}}});
 $gold=$db->executeQuery('SELECT a.* FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id = ? ORDER BY a.id',[$id])->fetchAll();
 $goldQuestions=$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_questions WHERE form_id = ? ORDER BY id',[$id])->fetchAll();$goldSubmissions=$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_submissions WHERE form_id = ? ORDER BY id',[$id])->fetchAll();$goldOptions=$db->executeQuery('SELECT o.* FROM *PREFIX*forms_v2_options o JOIN *PREFIX*forms_v2_questions q ON q.id=o.question_id WHERE q.form_id = ? ORDER BY o.id',[$id])->fetchAll();
 $share=new \OCA\Forms\Db\Share();$share->setFormId($id);$share->setShareType(0);$share->setShareWith($outsider['uid']);$share->setPermissions(['submit']);$share=Server::get(\OCA\Forms\Db\ShareMapper::class)->insert($share);
 $policy=Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
 $complete=static function(string $tool,array $args)use($write,$call,&$checks):array {$r=$write($tool,$args);$original=$r['operation']['operation_id'];for($i=0;($r['operation']['state']??null)==='pending'&&$i<100;$i++){$r=$write('forms_continue_operation',['target_operation_id'=>$original]);}$state=$r['operation']['state']??null;$refs=$r['operation']['references']??[];
  if(!($r['data']['complete']??false)||!in_array($state,['succeeded','partial'],true))throw new RuntimeException('Authoring data effects did not complete: '.$tool);
  if($state==='partial'){
   if(($refs['database']??null)!=='committed'||!in_array($refs['files']??null,['verified','not_applicable'],true)||($refs['notifications']??[])===[])throw new RuntimeException('Partial authoring lacks verified database/Files effects.');
   foreach($refs['notifications']as$event)if(($event['phase']??null)!=='attempted_unverified')throw new RuntimeException('Unexpected partial authoring event evidence.');
   $latest=$call('forms_get_operation',['operation_id'=>$r['operation']['operation_id']]);
   if(($latest['operation_id']??null)!==$r['operation']['operation_id']||($latest['state']??null)!=='partial'||($latest['references']['database']??null)!=='committed'||($latest['references']['files']??null)!=='verified'||\OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal::canonical($latest['references']??[])!==\OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal::canonical($refs))throw new RuntimeException('Fresh exact sharing receipt differs from verified terminal step.');
   $checks[$tool.'_fresh_exact_receipt']=true;
  }
  return $r;};
 foreach(['forms_update'=>['id'=>$id,'fields'=>['title'=>'HTTP scaled title','description'=>'HTTP complete metadata']], 'forms_update_share'=>['id'=>$id,'share_id'=>$share->getId(),'permissions'=>['submit','results']], 'forms_unshare'=>['id'=>$id,'share_id'=>$share->getId()]] as $tool=>$args){
  $stage=$tool.' current original admission';$op=$tool==='forms_update'?'edit':'share';$policy->setGrant($uid,'forms',$op,false);try{$deny($tool,$args);}finally{$policy->setGrant($uid,'forms',$op,true);}
  $p=$call($tool,$args);if(($p['impact']['bulk']['recovery_scope']??null)!=='none')throw new RuntimeException('Metadata preview claimed restorable backup.');
  if($tool==='forms_update_share'){$cursor=$p['impact']['bulk']['collections']['managed_shares']['cursor'];$page=$call('forms_read_operation_impact',['cursor'=>$cursor]);if(($page['collection']??null)!=='managed_shares')throw new RuntimeException('New authoring inspection route unavailable.');}
  $r=$complete($tool,$args);$refs=$r['operation']['references'];if(isset($refs['backup_id'])||($refs['backup_format']??null)!=='authoring_effects_v1'||($refs['recovery_scope']??null)!=='none')throw new RuntimeException('Metadata effects became restorable backup.');
  if($tool==='forms_update'){ $f=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);if($f->getTitle()!=='HTTP scaled title'||$f->getDescription()!=='HTTP complete metadata')throw new RuntimeException('Requested fields not applied.');}
  if($tool==='forms_update_share'&&Server::get(\OCA\Forms\Db\ShareMapper::class)->findById($share->getId())->getPermissions()!==['submit','results'])throw new RuntimeException('Native results share not updated.');
  if($tool==='forms_unshare'&&$db->executeQuery('SELECT id FROM *PREFIX*forms_v2_shares WHERE id = ?',[$share->getId()])->fetchOne()!==false)throw new RuntimeException('Native share retained after unshare.');
  $current=$db->executeQuery('SELECT a.* FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id = ? ORDER BY a.id',[$id])->fetchAll();if($current!==$gold)throw new RuntimeException('Metadata changed unrelated responses.');$checks[$tool.'_complete_scoped_metadata']=true;if(($r['operation']['state']??null)==='partial')$checks[$tool.'_partial_event_attempt_honest']=true;
 }
 $stage='question hide admission';$args=['id'=>$id,'question_id'=>$q];$policy->setGrant($uid,'forms','edit_questions',false);try{$deny('forms_delete_question',$args);}finally{$policy->setGrant($uid,'forms','edit_questions',true);}
 $p=$call('forms_delete_question',$args);if(($p['impact']['bulk']['counts_scope']??null)!=='recovery'||($p['before']['question']['id']??null)!==$q)throw new RuntimeException('Question recovery preview is ambiguous.');
 $r=$complete('forms_delete_question',$args);$backup=$r['operation']['references']['backup_id']??null;if(!is_string($backup))throw new RuntimeException('Question lost sealed recovery.');
 $current=$db->executeQuery('SELECT a.* FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id = ? ORDER BY a.id',[$id])->fetchAll();if($current!==$gold||Server::get(\OCA\Forms\Db\QuestionMapper::class)->findById($q)->getOrder()!==0||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_options WHERE question_id = ?',[$q])->fetchOne()!==2)throw new RuntimeException('Question native history differs.');$checks['question_native_history_preserved']=true;
 $stage='question archive restoration';$r=$complete('forms_restore',['backup_id'=>$backup]);$new=$r['operation']['references']['restored_form_id'];
 if($new===$id||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id = ?',[$new])->fetchOne()!==count($gold))throw new RuntimeException('Recovered answer set differs.');
 $oracle=static function()use($db,$new,$gold,$goldQuestions,$goldSubmissions,$goldOptions):bool {
  $strip=static function(array $r,array $keys):array{foreach($keys as $key)unset($r[$key]);return $r;};
  $questions=$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_questions WHERE form_id = ? ORDER BY id',[$new])->fetchAll();
  $submissions=$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_submissions WHERE form_id = ? ORDER BY id',[$new])->fetchAll();
  if(count($questions)!==count($goldQuestions)||count($submissions)!==count($goldSubmissions))return false;
  $qmap=[];foreach($goldQuestions as $before){$found=array_values(array_filter($questions,static fn(array $r):bool=>$r['text']===$before['text']&&$r['type']===$before['type']));if(count($found)!==1||$strip($found[0],['id','form_id'])!==$strip($before,['id','form_id']))return false;$qmap[(int)$before['id']]=(int)$found[0]['id'];}
  $smap=[];foreach($goldSubmissions as $before){$found=array_values(array_filter($submissions,static fn(array $r):bool=>$r['user_id']===$before['user_id']&&(int)$r['timestamp']===(int)$before['timestamp']));if(count($found)!==1||$strip($found[0],['id','form_id'])!==$strip($before,['id','form_id']))return false;$smap[(int)$before['id']]=(int)$found[0]['id'];}
  $options=$db->executeQuery('SELECT o.* FROM *PREFIX*forms_v2_options o JOIN *PREFIX*forms_v2_questions q ON q.id=o.question_id WHERE q.form_id = ? ORDER BY o.id',[$new])->fetchAll();
  if(count($options)!==count($goldOptions))return false;
  foreach($goldOptions as $before){$before['question_id']=$qmap[(int)$before['question_id']];$found=array_values(array_filter($options,static fn(array $r):bool=>$r['text']===$before['text']&&(int)$r['question_id']===(int)$before['question_id']));if(count($found)!==1||$strip($found[0],['id'])!==$strip($before,['id']))return false;}
  $answers=$db->executeQuery('SELECT a.* FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id = ? ORDER BY a.id',[$new])->fetchAll();
  if(count($answers)!==count($gold))return false;
  $texts=[];foreach($answers as $row){if(isset($texts[$row['text']]))return false;$texts[$row['text']]=$row;}
  foreach($gold as $before){$before['question_id']=$qmap[(int)$before['question_id']];$before['submission_id']=$smap[(int)$before['submission_id']];$actual=$texts[$before['text']]??null;if($actual===null||$strip($actual,['id'])!==$strip($before,['id']))return false;}
  return true;
 };
 if(!$oracle())throw new RuntimeException('Independent source values/question/submission/options recovery differs.');
 $sample=$db->executeQuery('SELECT a.* FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id = ? ORDER BY a.id LIMIT 1',[$new])->fetch();
 foreach(['question_id'=>'forms_v2_questions','submission_id'=>'forms_v2_submissions']as$column=>$table){$wrong=$db->executeQuery('SELECT id FROM *PREFIX*'.$table.' WHERE form_id = ? AND id <> ? ORDER BY id LIMIT 1',[$new,$sample[$column]])->fetchOne();try{$db->executeStatement('UPDATE *PREFIX*forms_v2_answers SET '.$column.' = ? WHERE id = ?',[$wrong,$sample['id']]);if($oracle())throw new RuntimeException('Recovery oracle accepted wrong '.$column);}finally{$db->executeStatement('UPDATE *PREFIX*forms_v2_answers SET '.$column.' = ? WHERE id = ?',[$sample[$column],$sample['id']]);}}
 if(!$oracle())throw new RuntimeException('Oracle negative controls did not restore baseline.');$checks['independent_recovery_oracle_rejects_wrong_bindings']=true;
 $f=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($new);if($f->getOwnerId()!==$uid||$f->getAccessEnum()!==0||$f->getState()!==1||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_shares WHERE form_id = ?',[$new])->fetchOne()!==0)throw new RuntimeException('Recovery privacy changed.');$checks['sealed_exact_private_recovery']=true;
 echo json_encode(['ok'=>true,'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$e->getMessage(),'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";$exitCode=1;}
finally{if($config!==null&&$global!==null)$config->setValueBool('forms','allowComments',$global);}
exit($exitCode);
