<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Forms\Mcp\Service\{OriginContext,NativeActions,FormsEffectOutbox,ApprovedFormsOperation};
try {
 Server::get(\OCP\App\IAppManager::class)->loadApp('forms');Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
 $in=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);$a=$in['args']??[];$uid=$in['actor']??'guard-owner';
 Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));
 $c=(new \OCA\Forms\AppInfo\Application())->getContainer();$api=$c->get(\OCA\Forms\Controller\ApiController::class);$guard=$c->get(\OCA\Forms\Mcp\Guard::class);$db=Server::get(\OCP\IDBConnection::class);
 require __DIR__.'/origin-fixture.php';
 $result=match($in['action']) {
 'fixture'=>(function()use($api,$guard,$uid,$a,$db){
   Server::get(\OCP\IAppConfig::class)->setValueBool('forms','allowConfirmationEmail',true);
   $id=$api->newForm()->getData()['id'];$q=$api->newQuestion($id,'short',null,'Email')->getData()['id'];
   $api->updateQuestion($id,$q,['extraSettings'=>['validationType'=>'email']]);
   $api->updateForm($id,['submitMultiple'=>true,'allowEditSubmissions'=>true,'confirmationEmailEnabled'=>true,'confirmationEmailQuestionId'=>$q]);
   $input=['id'=>$id,'answers'=>[$q=>['test-'.bin2hex(random_bytes(4)).'@example.invalid']]];$origin=formsNativeOrigin($uid,'forms_submit_response',$input);$input['_mcp_origin']=$origin;
   $snap=$guard->snapshot($uid,'forms_submit_response',$input);$key=bin2hex(random_bytes(16));
   $receipt=OriginContext::within($uid,$origin,'forms_submit_response',$input,fn()=>Server::get(ApprovedFormsOperation::class)->apply($uid,'forms_submit_response',$input,$snap['revision'],$key,static fn()=>null,function()use($input){
      $r=NativeActions::invoke('forms_submit_response',$input);
      // Simulate the commit-to-dispatch process gap without changing production execution logic.
      (new ReflectionProperty(FormsEffectOutbox::class,'pending'))->setValue(Server::get(FormsEffectOutbox::class),[]);return $r;
   }));
   $effects=$db->executeQuery('SELECT effect_key,phase FROM *PREFIX*forms_mcp_effects WHERE operation_key = ?',[$key])->fetchAll();
   return ['id'=>$id,'submission_id'=>$receipt['references']['submission_id'],'key'=>$key,'effects'=>$effects,'origin'=>formsNativeOrigin($uid,'forms_download_results',['id'=>$id])];
 })(),
 'drain'=>(function()use($a,$db){$outbox=Server::get(FormsEffectOutbox::class);(new ReflectionProperty(FormsEffectOutbox::class,'pending'))->setValue($outbox,[$a['effect_key']]);$outbox->drain();return $db->executeQuery('SELECT kind,phase,payload_ref FROM *PREFIX*forms_mcp_effects WHERE operation_key = ? ORDER BY kind',[$a['key']])->fetchAll();})(),
 'change'=>(function()use($a,$api){$api->updateForm($a['id'],['title'=>'Changed after approval']);return true;})(),
 'gate'=>(function()use($a,$uid){Server::get(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class)->runInternal([$uid],static fn()=>null,static function()use($a,$uid){Server::get(\OCA\Mcp\Db\ToolGrantsMapper::class)->set('user',$uid,'forms_submit_response',$a['allow']?'allow':'deny');return true;});return true;})(),
 'deferred'=>(function()use($guard,$a){Server::get(\OCP\IUserSession::class)->setUser(null);$r=$guard->deferredRead($a['origin'],'forms_results_page',['id'=>$a['id'],'limit'=>10]);return ['count'=>count($r['submissions']),'session'=>Server::get(\OCP\IUserSession::class)->getUser()?->getUID(),'revision'=>$r['revision']];})(),
 'mail'=>(function()use($a,$db){
   $mailer=new class(Server::get(\OCP\Mail\IMailer::class)) implements \OCP\Mail\IMailer {
     public int $sent=0;public function __construct(private \OCP\Mail\IMailer $native){}
     public function createMessage():\OCP\Mail\IMessage{return $this->native->createMessage();}
     public function createAttachment($data=null,$filename=null,$contentType=null):\OCP\Mail\IAttachment{return $this->native->createAttachment($data,$filename,$contentType);}
     public function createAttachmentFromPath(string $path,$contentType=null):\OCP\Mail\IAttachment{return $this->native->createAttachmentFromPath($path,$contentType);}
     public function createEMailTemplate(string $emailId,array $data=[]):\OCP\Mail\IEMailTemplate{return $this->native->createEMailTemplate($emailId,$data);}
     public function send(\OCP\Mail\IMessage $message):array{$this->sent++;return [];}
     public function validateMailAddress(string $email):bool{return $this->native->validateMailAddress($email);}
   };
   Server::get(\OCP\IUserSession::class)->setUser(null);
   $job=new \OCA\Forms\BackgroundJob\SendConfirmationMailJob(Server::get(\OCP\AppFramework\Utility\ITimeFactory::class),$mailer,Server::get(\Psr\Log\LoggerInterface::class),Server::get(\OCP\Defaults::class));
   $job->run(['_mcp_mail'=>$a['ref']]);$phase=$db->executeQuery('SELECT phase FROM *PREFIX*forms_mcp_effects WHERE effect_key = ?',[$a['ref']])->fetchOne();
   return ['sent'=>$mailer->sent,'phase'=>$phase,'session'=>Server::get(\OCP\IUserSession::class)->getUser()?->getUID()];
 })(),
 'jobs'=>$db->executeQuery('SELECT argument FROM *PREFIX*jobs WHERE class = ? AND argument LIKE ?',[\OCA\Forms\BackgroundJob\SendConfirmationMailJob::class,'%'.$a['ref'].'%'])->fetchAll(),
 'file_fault'=>(function()use($api,$guard,$uid,$db){
   $id=$api->newForm()->getData()['id'];$q=$api->newQuestion($id,'file',null,'Fault attachment')->getData()['id'];$input=['id'=>$id,'question_id'=>$q];$input['_mcp_origin']=formsNativeOrigin($uid,'forms_upload_response_files',$input);$snap=$guard->snapshot($uid,'forms_upload_response_files',$input);$key=bin2hex(random_bytes(16));$storage=null;$path=null;
   try {$guard->apply($uid,'forms_upload_response_files',$input,$snap['revision'],$key,$snap['file_manifest'],function()use($guard,$uid,$id,$q,$db,&$storage,&$path){
     $stream=fopen('php://memory','w+b');fwrite($stream,'staged-before-native-refusal');rewind($stream);
     try {$guard->stageFiles($uid,$id,$q,'',[['name'=>'fault.txt','size'=>strlen('staged-before-native-refusal'),'sha256'=>hash('sha256','staged-before-native-refusal'),'stream'=>$stream]]);}finally{fclose($stream);}
     $fileId=(int)$db->executeQuery('SELECT file_id FROM *PREFIX*forms_v2_uploaded_files WHERE form_id = ?',[$id])->fetchOne();$file=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->getById($fileId)[0];$storage=$file->getStorage();$path=$file->getInternalPath();
     throw new \OCP\AppFramework\OCS\OCSBadRequestException('Injected native refusal after staging');
   });throw new RuntimeException('Fault was not injected');}catch(\OCP\AppFramework\OCS\OCSBadRequestException $e){return ['class'=>$e::class,'bytes_exist'=>$storage->file_exists($path),'operation_rows'=>(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_operations WHERE operation_key = ?',[$key])->fetchOne()];}
 })(),
 'grid'=>(function()use($api,$guard,$uid,$db){
   $id=$api->newForm()->getData()['id'];$q=$api->newQuestion($id,'grid','number','Numeric grid')->getData()['id'];
   $row=$api->newOption($id,$q,['Row'],'row')->getData()[0]['id'];$cols=$api->newOption($id,$q,['Zero','Number'],'column')->getData();
   $api->newSubmission($id,[$q=>[$row=>[$cols[0]['id']=>0,$cols[1]['id']=>$cols[0]['id']]]]);
   $write=function($action,$input)use($guard,$uid){$input['_mcp_origin']=formsNativeOrigin($uid,$action,$input);$snap=$guard->snapshot($uid,$action,$input);return $guard->apply($uid,$action,$input,$snap['revision'],bin2hex(random_bytes(16)),$snap['file_manifest']);};
   $deleted=$write('forms_delete_form',['id'=>$id]);$restored=$write('forms_restore',['backup_id'=>$deleted['references']['backup_id']]);$newId=$restored['references']['form_id'];
   $questions=Server::get(\OCA\Forms\Service\FormsService::class)->getQuestions($newId);$newQ=$questions[0]['id'];
   $raw=$db->executeQuery('SELECT text FROM *PREFIX*forms_v2_answers WHERE question_id = ?',[$newQ])->fetchOne();$answer=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
   Server::get(\OCA\Forms\Service\SubmissionService::class)->validateSubmission($questions,[$newQ=>$answer],$uid,$newId);
   return ['answer'=>$answer,'options'=>$questions[0]['options'],'original_value'=>$cols[0]['id'],'phase'=>$restored['phases']['files']];
 })(),
 'rotate'=>(function()use($uid){Server::get(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class)->underMutex(fn()=>Server::get(\OCA\Mcp\Mcp\Operations\ActorIdentity::class)->rotateBeforeCreation($uid));return true;})(),
 default=>throw new RuntimeException('Unknown origin fixture action'),
 };
 echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'class'=>$e::class],JSON_THROW_ON_ERROR)."\n";}
