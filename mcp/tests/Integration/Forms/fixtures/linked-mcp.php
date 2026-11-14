<?php
/* SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_LINKED_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);
require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Forms\Db\{FormMapper,SubmissionMapper,Submission};
use OCA\Forms\Mcp\Service\{FormsRevisionFence,LinkedMarkers};
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $request=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
    $guard=Server::get(\OCA\Forms\Mcp\Guard::class);
    if (!method_exists($guard,'linkedClaim')) {echo json_encode(['ok'=>true,'result'=>['native_linked_api'=>false]]);exit;}
    $uid='mcp-linked-'.bin2hex(random_bytes(5));Server::get(\OCP\IUserManager::class)->createUser($uid,bin2hex(random_bytes(24))); $session=Server::get(\OCP\IUserSession::class); $session->setUser(Server::get(\OCP\IUserManager::class)->get($uid));
    $config=Server::get(\OCP\IConfig::class);$config->deleteUserValue($uid,'mcp','forms_native_test_ready');
    $db=Server::get(\OCP\IDBConnection::class);$fence=Server::get(FormsRevisionFence::class);
    $api=(new \OCA\Forms\AppInfo\Application())->getContainer()->get(\OCA\Forms\Controller\ApiController::class);
    $questionType=str_contains($request['case'],'cleanup_')&&str_contains($request['case'],'question')?'file':'short';
    $id=$api->newForm()->getData()['id'];$question=$api->newQuestion($id,$questionType,null,'Linked fixture question')->getData()['id'];
    require __DIR__.'/origin-fixture.php';formsNativeOrigin($uid,'forms_summarize_results',['id'=>$id]);
    // Issue a real persistent OAuth connection, then retain its exact selected tools in the native proof.
    $clients=Server::get(\OCA\Mcp\Db\OAuthClientsMapper::class);
    if (!\OCA\Mcp\OAuth\ClientResolver::nativeEnabled($clients)) {
        Server::get(\OCA\Mcp\OAuth\OAuthClientsService::class)->update(Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['revision'],null,true,null);
    }
    $verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $redirect=\OCA\Mcp\OAuth\NativeClient::REDIRECT_URIS[0];$resource='http://localhost/apps/mcp/';
    $tokens=Server::get(\OCA\Mcp\OAuth\TokenService::class);
    $authorization=new \OCA\Mcp\OAuth\AuthorizationRequest(\OCA\Mcp\OAuth\NativeClient::metadata((string)\OCA\Mcp\OAuth\ClientResolver::nativeGeneration($clients)),$redirect,'linked-fixture',$challenge,$resource,'mcp');
    $code=$tokens->consent($authorization,$uid,['forms_summarize_results'],[]);
    $tokens->exchangeCode(['code'=>$code,'code_verifier'=>$verifier,'client_id'=>\OCA\Mcp\OAuth\NativeClient::CLIENT_ID,'redirect_uri'=>$redirect,'resource'=>$resource]);
    $connection=(int)$db->executeQuery('SELECT MAX(id) FROM *PREFIX*mcp_oauth_tokens WHERE user_id = ?',[$uid])->fetchOne();
    $selection=Server::get(\OCA\Mcp\OAuth\ConnectionToolsStore::class);$saved=$selection->get($uid,$connection);
    $operations=$request['case']==='file_refs'?['link_results','read_response_files']:['link_results'];
    $selection->save($uid,$connection,['forms_link_results_file','forms_unlink_results_file','forms_summarize_results'],$saved['revision'],['forms_link_results_file'=>$operations]);
    $origin=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsOriginProof::class)->create($uid,$connection,'forms_link_results_file',$operations,['form_id'=>$id,'owner_id'=>$uid,'files_operations'=>['read','edit','create']]);
    $cleanup=null;
    if (str_starts_with($request['case'],'cleanup_')) {
        $targetId=str_contains($request['case'],'cross')?$api->newForm()->getData()['id']:$id;
        $targetQuestion=$targetId===$id?$question:$api->newQuestion($targetId,$questionType,null,'Cleanup target')->getData()['id'];
        $submissionIds=[];
        for ($i=0;$i<2;$i++) {
            $submission=new Submission();$submission->setFormId($targetId);$submission->setUserId($uid);$submission->setTimestamp(time());
            $submissionIds[]=Server::get(SubmissionMapper::class)->insert($submission)->getId();
        }
        $cleanup=['form_id'=>$targetId,'question_id'=>$targetQuestion,'submission_ids'=>$submissionIds];
    }
    $metadata=$guard->deferredRead($origin,'forms_results_metadata',['id'=>$id]);
    $root=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$name='native-linked-'.bin2hex(random_bytes(6)).'.csv';
    if ($request['case']==='managed_folder') {
        $formsFolder=$root->nodeExists('Forms')?$root->get('Forms'):$root->newFolder('Forms');
        $formsFolder->newFolder($id.' - Linked export');$name='Forms/'.$id.' - Linked export/'.$name;
    }
    if ($cleanup!==null) {
        $formsFolder=$root->nodeExists('Forms')?$root->get('Forms'):$root->newFolder('Forms');
        $targetFolder=$formsFolder->newFolder($cleanup['form_id'].' - Retained cleanup');
        $kept=$targetFolder->newFolder((string)$cleanup['submission_ids'][0]);$ordinary=$targetFolder->newFolder((string)$cleanup['submission_ids'][1]);
        if (str_contains($request['case'],'question')) {
            $kept=$kept->newFolder($cleanup['question_id'].' - Files');$ordinary=$ordinary->newFolder($cleanup['question_id'].' - Files');
        }
        if (str_ends_with($request['case'],'_form')) {$ordinary=$formsFolder->newFolder($cleanup['form_id'].' - Ordinary cleanup');}
        $ordinary->newFile('ordinary.txt','ordinary native cleanup');
        $cleanup['ordinary_path']=ltrim($root->getRelativePath($ordinary->getPath()),'/');
        $name=ltrim($root->getRelativePath($kept->getPath()),'/').'/'.$name;
    }
    $file=$root->newFile($name,'before');
    $receipt=static function($file)use($root):array {return ['file_id'=>(int)$file->getId(),'path'=>ltrim($root->getRelativePath($file->getPath()),'/'),'etag'=>(string)$file->getEtag(),'size'=>(int)$file->getSize(),'sha256'=>hash('sha256',$file->getContent())];};
    $input=['id'=>$id,'format'=>'csv','_mcp_origin'=>$origin,'_mcp_link_approval'=>[
        'arguments'=>['id'=>$id,'format'=>'csv','path'=>$name,'overwrite'=>true,'etag'=>$file->getEtag()],
        'native_runtime'=>LinkedMarkers::runtime(),'schema_digest'=>LinkedMarkers::schemaDigest($metadata),'source_revision'=>$metadata['export_revision'],
        'destination'=>['path'=>$name,'file_id'=>(int)$file->getId(),'overwrite'=>true],
    ]];


    $copies=$request['case']==='copies_true';
    $before=$db->executeQuery('SELECT configvalue,type FROM *PREFIX*appconfig WHERE appid = ? AND configkey = ?',['mcp','backup_settings'])->fetch();
    try {
        if($before===false)$db->executeStatement('INSERT INTO *PREFIX*appconfig (appid,configkey,configvalue,type,lazy) VALUES (?,?,?,?,0)',
            ['mcp','backup_settings',json_encode(['version'=>1,'folder'=>'MCP backups','reserved'=>['MCP backups'],'copies'=>$copies]),\OCP\IAppConfig::VALUE_STRING]);
        else $db->executeStatement('UPDATE *PREFIX*appconfig SET configvalue = ?,type = ? WHERE appid = ? AND configkey = ?',
            [json_encode(['version'=>1,'folder'=>'MCP backups','reserved'=>['MCP backups'],'copies'=>$copies]),\OCP\IAppConfig::VALUE_STRING,'mcp','backup_settings']);
        $file->touch(time()-120);$file=$root->get($name);$input['_mcp_link_approval']['arguments']['etag']=$file->getEtag();
        $args=$input['_mcp_link_approval']['arguments'];$policy=Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
        $destination=$policy->resourceBoundary($uid,fn()=>Server::get(\OCA\Mcp\Modules\Forms\Exports\FormsExportDestination::class)->snapshot($uid,$args));
        $audience=new \OCA\Mcp\Modules\Forms\Exports\FormsLinkedAudience(Server::get(\OCP\Files\IRootFolder::class),$db,static fn():int=>time());
        $input['_mcp_link_approval']['destination']=$destination+['audience'=>$audience->snapshot($uid,$name)];
        $linked=$guard->apply($uid,'forms_link_results_file',$input,$fence->revision(),bin2hex(random_bytes(32)),[],
            function()use($receipt,$file,$destination):array {$r=$receipt($file);$r['path']=$destination['path'];return $r;});
        $association=$linked['references']['association_id'];
        $submission=new Submission();$submission->setFormId($id);$submission->setUserId($uid);$submission->setTimestamp(time());
        $submission=Server::get(SubmissionMapper::class)->insert($submission);
        $answer=new \OCA\Forms\Db\Answer();$answer->setSubmissionId($submission->getId());$answer->setQuestionId($question);$answer->setText('original-authority-answer');
        Server::get(\OCA\Forms\Db\AnswerMapper::class)->insert($answer);
        $runner=Server::get(\OCA\Mcp\Modules\Forms\Exports\FormsLinkedExportRunner::class);
        $advance=function()use($request,$runner,$association,$db):array {
            if($request['case']!=='cron')return $runner->run($association);
            $class=\OCA\Mcp\BackgroundJob\FormsLinkedExportJob::class;
            Server::get(\OCP\BackgroundJob\IJobList::class)->add($class);
            $jobId=$db->executeQuery('SELECT id FROM *PREFIX*jobs WHERE class = ? ORDER BY id ASC',[$class])->fetchOne();
            if($jobId===false)throw new RuntimeException('Scheduled linked job was not registered.');
            $process=proc_open([PHP_BINARY,'/var/www/html/occ','background-job:execute',(string)$jobId,'--force-execute','--no-interaction','--no-ansi'],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,'/var/www/html',null,['bypass_shell'=>true]);
            if(!is_resource($process))throw new RuntimeException('Scheduled linked job did not start.');
            fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            if(proc_close($process)!==0)throw new RuntimeException('Scheduled linked job failed.');
            return ['status'=>'completed'];
        };
        for($step=0;$step<5;$step++) {
            $ack=$advance();
            if(($ack['status']??null)!=='completed')throw new RuntimeException('The isolated MCP linked step did not complete.');
            $row=$db->executeQuery('SELECT state,dirty_generation,completed_generation FROM *PREFIX*forms_mcp_linked WHERE association_id = ?',[$association])->fetch();
            if($row['state']==='active'&&(int)$row['dirty_generation']===(int)$row['completed_generation'])break;
        }
        if($step===5||!str_contains($file->getContent(),'original-authority-answer'))throw new RuntimeException('The full MCP cycle did not deliver the complete source.');
        $verified=$file->getContent();
        $second=new Submission();$second->setFormId($id);$second->setUserId($uid);$second->setTimestamp(time());Server::get(SubmissionMapper::class)->insert($second);
        $policy->setGrant($uid,'forms','link_results',false);$advance();
        $row=$db->executeQuery('SELECT state FROM *PREFIX*forms_mcp_linked WHERE association_id = ?',[$association])->fetch();
        if($row['state']!=='paused'||$file->getContent()!==$verified)throw new RuntimeException('Revoked original authority did not pause without overwriting.');
        $policy->setGrant($uid,'forms','link_results',true);$advance();
        if($db->executeQuery('SELECT state FROM *PREFIX*forms_mcp_linked WHERE association_id = ?',[$association])->fetchOne()!=='paused'||$file->getContent()!==$verified)
            throw new RuntimeException('Restored grant resumed old consent automatically.');
        echo json_encode(['ok'=>true,'checks'=>['isolated_cycle'=>true,'complete_source'=>true,'revocation_pauses'=>true,'restoration_does_not_resume'=>true]],JSON_THROW_ON_ERROR)."\n";
    } finally {
        if($before===false)$db->executeStatement('DELETE FROM *PREFIX*appconfig WHERE appid = ? AND configkey = ?',['mcp','backup_settings']);
        else $db->executeStatement('UPDATE *PREFIX*appconfig SET configvalue = ?,type = ? WHERE appid = ? AND configkey = ?',[$before['configvalue'],$before['type'],'mcp','backup_settings']);
    }
} catch(Throwable $error) {echo json_encode(['ok'=>false,'error'=>$error::class,'message'=>$error->getMessage()],JSON_THROW_ON_ERROR)."\n";exit(1);}

