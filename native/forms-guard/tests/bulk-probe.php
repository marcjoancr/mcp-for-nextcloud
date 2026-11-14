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
 $currentControl=getenv('MCP_BULK_CURRENT_CONTROL')==='1';
 $control=static function(array $origin)use($uid,$currentControl):array{return !$currentControl?$origin:Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->capture($uid,'forms_continue_operation',isset($origin['references']['form_id'])?['id'=>$origin['references']['form_id']]:[],['bulk_authority'=>['tool'=>$origin['tool'],'required_operations'=>$origin['operations'],'files_operations'=>$origin['references']['files_operations']??[]]]);};

 if (!method_exists($g,'bulkStart')) {throw new RuntimeException('Missing bounded Forms bulk lifecycle');}
 $api=Server::get(\OCA\Forms\Controller\ApiController::class); $db=Server::get(\OCP\IDBConnection::class);
 $id=$api->newForm()->getData()['id']; $api->updateForm($id,['submitMultiple'=>true]);
 $questions=[];for($i=0;$i<11;$i++){$questions[]=$api->newQuestion($id,'short',null,'Field '.$i)->getData()['id'];}
 for($i=0;$i<103;$i++){$answers=[];foreach($questions as$q){$answers[$q]=['answer '.$i];}$api->newSubmission($id,$answers);}
 $input=['id'=>$id];$input['_mcp_origin']=formsNativeOrigin($uid,'forms_delete_responses',$input);
 $preview=$g->bulkSnapshot($uid,'forms_delete_responses',$input);
 $job=$g->bulkStart($uid,'forms_delete_responses',$input,$preview['revision'],bin2hex(random_bytes(16)));
 $steps=0;while($job['next']&&$steps++<100){$job=$g->bulkStep($uid,$job['bulk_id'],$control($input['_mcp_origin']),$job['version'],bin2hex(random_bytes(16)));}
 $checks=['completed'=>$job['state']==='completed','multiple_steps'=>$steps>10,'all_removed'=>(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_submissions WHERE form_id = ?',[$id])->fetchOne()===0];
 if($currentControl){
  $removed=$db->executeStatement('DELETE FROM *PREFIX*migrations WHERE app = ? AND version = ?',['forms','050402Date20261113070000']);if($removed!==1){throw new RuntimeException('Expected comments writer schema');}
  register_shutdown_function(static function()use($db):void{$db->executeStatement('INSERT INTO *PREFIX*migrations (app,version) VALUES (?,?)',['forms','050402Date20261113070000']);$p=proc_open([PHP_BINARY,'/tmp/forms-parity-installer/install.php','--root=/var/www/html','--action=validate'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0){fwrite(STDERR,$err.$out);}});
  $checks['restore_without_source_writer']=!$g->capabilities()['bulk_source_writer_ready'];
 }
 $restore=['backup_id'=>$job['references']['backup_id']];$restore['_mcp_origin']=formsNativeOrigin($uid,'forms_restore',$restore);
 $preview=$g->bulkSnapshot($uid,'forms_restore',$restore);$job=$g->bulkStart($uid,'forms_restore',$restore,$preview['revision'],bin2hex(random_bytes(16)));
 $steps=0;while($job['next']&&$steps++<100){$job=$g->bulkStep($uid,$job['bulk_id'],$control($restore['_mcp_origin']),$job['version'],bin2hex(random_bytes(16)));}
 $restored=$job['references']['restored_form_id']??0;
 $checks['restored_complete']=$job['state']==='completed'&&(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_submissions WHERE form_id = ?',[$restored])->fetchOne()===103;
 $checks['all_answers']=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id = ?',[$restored])->fetchOne()===1133;
 $f=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($restored);$checks['private_closed']=$f->getState()===1&&$f->getAccessEnum()===0;
 echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks,'last'=>$job],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()],JSON_THROW_ON_ERROR)."\n";exit(1);}
