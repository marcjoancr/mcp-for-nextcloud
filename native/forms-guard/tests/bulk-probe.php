<?php
declare(strict_types=1);
define('OC_CONSOLE',true);
require '/var/www/html/lib/base.php';
use OCP\Server;
try {
 Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
 $uid='guard-owner'; Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));
 require '/tmp/origin-fixture.php';
 $g=Server::get(\OCA\Forms\Mcp\Guard::class);
 if (!method_exists($g,'bulkStart')) {throw new RuntimeException('Missing bounded Forms bulk lifecycle');}
 $api=Server::get(\OCA\Forms\Controller\ApiController::class); $db=Server::get(\OCP\IDBConnection::class);
 $id=$api->newForm()->getData()['id']; $api->updateForm($id,['submitMultiple'=>true]);
 $questions=[];for($i=0;$i<11;$i++){$questions[]=$api->newQuestion($id,'short',null,'Field '.$i)->getData()['id'];}
 for($i=0;$i<103;$i++){$answers=[];foreach($questions as$q){$answers[$q]=['answer '.$i];}$api->newSubmission($id,$answers);}
 $input=['id'=>$id];$input['_mcp_origin']=formsNativeOrigin($uid,'forms_delete_responses',$input);
 $preview=$g->bulkSnapshot($uid,'forms_delete_responses',$input);
 $job=$g->bulkStart($uid,'forms_delete_responses',$input,$preview['revision'],bin2hex(random_bytes(16)));
 $steps=0;while($job['next']&&$steps++<100){$job=$g->bulkStep($uid,$job['bulk_id'],$input['_mcp_origin'],$job['version'],bin2hex(random_bytes(16)));}
 $checks=['completed'=>$job['state']==='completed','multiple_steps'=>$steps>10,'all_removed'=>(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_submissions WHERE form_id = ?',[$id])->fetchOne()===0];
 $restore=['backup_id'=>$job['references']['backup_id']];$restore['_mcp_origin']=formsNativeOrigin($uid,'forms_restore',$restore);
 $preview=$g->bulkSnapshot($uid,'forms_restore',$restore);$job=$g->bulkStart($uid,'forms_restore',$restore,$preview['revision'],bin2hex(random_bytes(16)));
 $steps=0;while($job['next']&&$steps++<100){$job=$g->bulkStep($uid,$job['bulk_id'],$restore['_mcp_origin'],$job['version'],bin2hex(random_bytes(16)));}
 $restored=$job['references']['restored_form_id']??0;
 $checks['restored_complete']=$job['state']==='completed'&&(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_submissions WHERE form_id = ?',[$restored])->fetchOne()===103;
 $checks['all_answers']=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id = ?',[$restored])->fetchOne()===1133;
 $f=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($restored);$checks['private_closed']=$f->getState()===1&&$f->getAccessEnum()===0;
 echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks,'last'=>$job],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()],JSON_THROW_ON_ERROR)."\n";exit(1);}
