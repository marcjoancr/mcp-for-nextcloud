<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
use OCP\Server;
$stage='bootstrap';$checks=[];$backupRow=null;$backupChanged=false;$exitCode=0;
$request=json_decode(stream_get_contents(STDIN),true)??['mode'=>'create'];$mode=$request['mode']??'create';
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $uid='link-http-'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(24));
    $user=Server::get(\OCP\IUserManager::class)->createUser($uid,$password);Server::get(\OCP\IUserSession::class)->setUser($user);
    $api=(new \OCA\Forms\AppInfo\Application())->getContainer()->get(\OCA\Forms\Controller\ApiController::class);
    $id=$api->newForm()->getData()['id'];$question=$api->newQuestion($id,'short',null,'Public linked field')->getData()['id'];
    require '/tmp/origin-fixture.php';formsNativeOrigin($uid,'forms_summarize_results',['id'=>$id]);
    $db=Server::get(\OCP\IDBConnection::class);
    $clients=Server::get(\OCA\Mcp\Db\OAuthClientsMapper::class);
    if(!\OCA\Mcp\OAuth\ClientResolver::nativeEnabled($clients))Server::get(\OCA\Mcp\OAuth\OAuthClientsService::class)->update(Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['revision'],null,true,null);
    $verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
    $challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $redirect=\OCA\Mcp\OAuth\NativeClient::REDIRECT_URIS[0];$resource='http://localhost/apps/mcp/';
    $tokens=Server::get(\OCA\Mcp\OAuth\TokenService::class);
    $authorization=new \OCA\Mcp\OAuth\AuthorizationRequest(\OCA\Mcp\OAuth\NativeClient::metadata((string)\OCA\Mcp\OAuth\ClientResolver::nativeGeneration($clients)),$redirect,'linked-http',$challenge,$resource,'mcp');
    $code=$tokens->consent($authorization,$uid,['forms_summarize_results'],[]);
    $token=$tokens->exchangeCode(['code'=>$code,'code_verifier'=>$verifier,'client_id'=>\OCA\Mcp\OAuth\NativeClient::CLIENT_ID,'redirect_uri'=>$redirect,'resource'=>$resource])['access_token'];
    $connection=(int)$db->executeQuery('SELECT MAX(id) FROM *PREFIX*mcp_oauth_tokens WHERE user_id = ?',[$uid])->fetchOne();
    $selection=Server::get(\OCA\Mcp\OAuth\ConnectionToolsStore::class);$saved=$selection->get($uid,$connection);
    $selection->save($uid,$connection,['forms_link_results_file','forms_unlink_results_file','forms_continue_operation','forms_read_settings','forms_get_operation','forms_summarize_results'],$saved['revision'],['forms_link_results_file'=>['link_results']]);
    $rpc=static function(string $name,array $args)use($token):array {
        $curl=curl_init('http://127.0.0.1/index.php/apps/mcp/');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,
            CURLOPT_HTTPHEADER=>['Host: localhost','Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-06-18','Authorization: Bearer '.$token],
            CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],JSON_THROW_ON_ERROR)]);
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$data=json_decode((string)$body,true);
        if($status!==200||!is_array($data)||isset($data['error']))throw new RuntimeException('Transport refused '.$name.' HTTP '.$status.' code '.($data['error']['code']??0));
        $result=$data['result'];if($result['isError']??false)throw new RuntimeException('Workflow refused '.$name.': '.($result['content'][0]['text']??''));
        return $result['structuredContent']??json_decode($result['content'][0]['text'],true,64,JSON_THROW_ON_ERROR);
    };
    $write=static function(string $name,array $args)use($rpc):array {
        $preview=$rpc($name,$args);if(!isset($preview['plan_state'],$preview['operation_id']))throw new RuntimeException('No approval envelope '.$name);
        return $rpc($name,$args+['confirm'=>true,'confirm_shared'=>true,'plan_state'=>$preview['plan_state'],'operation_id'=>$preview['operation_id']]);
    };
    $stage='preview and persistent activation';
    $backupRow=$db->executeQuery('SELECT configvalue,type FROM *PREFIX*appconfig WHERE appid = ? AND configkey = ?',['mcp','backup_settings'])->fetch();
    $copies=$mode!=='versions';
    $backupSettings=json_encode(['version'=>1,'folder'=>'MCP backups','reserved'=>['MCP backups'],'copies'=>$copies],JSON_THROW_ON_ERROR);
    if($backupRow===false)$db->executeStatement('INSERT INTO *PREFIX*appconfig (appid,configkey,configvalue,type,lazy) VALUES (?,?,?,?,0)',['mcp','backup_settings',$backupSettings,\OCP\IAppConfig::VALUE_STRING]);
    else $db->executeStatement('UPDATE *PREFIX*appconfig SET configvalue = ?,type = ? WHERE appid = ? AND configkey = ?',[$backupSettings,\OCP\IAppConfig::VALUE_STRING,'mcp','backup_settings']);
    $backupChanged=true;
    $path='public-linked-results.csv';$arguments=['id'=>$id,'path'=>$path,'format'=>'csv'];$home=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
    if($mode==='versions_disabled') {
        $enabled=$db->executeQuery('SELECT configvalue FROM *PREFIX*appconfig WHERE appid = ? AND configkey = ?',['files_versions','enabled'])->fetchOne();
        if($enabled===false)throw new RuntimeException('Versions enablement fixture is unavailable.');
        $beforeJournal=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*mcp_operations WHERE actor_uid = ?',[$uid])->fetchOne();
        $beforeNative=(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_operations')->fetchOne();$refusals=0;
        try {
            $db->executeStatement('UPDATE *PREFIX*appconfig SET configvalue = ? WHERE appid = ? AND configkey = ?',['no','files_versions','enabled']);
            foreach([true,false] as $preference) {
                $db->executeStatement('UPDATE *PREFIX*appconfig SET configvalue = ? WHERE appid = ? AND configkey = ?',
                    [json_encode(['version'=>1,'folder'=>'MCP backups','reserved'=>['MCP backups'],'copies'=>$preference],JSON_THROW_ON_ERROR),'mcp','backup_settings']);
                try {$rpc('forms_link_results_file',$arguments);}
                catch(RuntimeException $failure) {
                    if(!str_starts_with($failure->getMessage(),'Workflow refused forms_link_results_file'))throw $failure;
                    $refusals++;continue;
                }
                throw new RuntimeException('Disabled Versions was approved for future overwrites.');
            }
            if($refusals!==2||$home->nodeExists($path)
                ||$beforeJournal!==(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*mcp_operations WHERE actor_uid = ?',[$uid])->fetchOne()
                ||$beforeNative!==(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_operations')->fetchOne()
                ||(int)$db->executeQuery('SELECT COUNT(*) FROM *PREFIX*forms_mcp_linked WHERE form_id = ?',[$id])->fetchOne()!==0)
                throw new RuntimeException('Prerequisite refusal left effects or approval reservations.');
            echo json_encode(['ok'=>true,'checks'=>['rejected_before_effect'=>$refusals]],JSON_THROW_ON_ERROR);return;
        } finally {
            $db->executeStatement('UPDATE *PREFIX*appconfig SET configvalue = ? WHERE appid = ? AND configkey = ?',[$enabled,'files_versions','enabled']);
        }
    }
    if($mode!=='create') {
        $oldBytes='old preimage '.$uid;$old=$home->newFile($path,$oldBytes);$old->touch(time()-120);$old=$home->get($path);
        $originalId=(int)$old->getId();$oldRevision=Server::get('OCA\\Files_Versions\\Versions\\IVersionManager')->getRevision($old);
        $arguments+=['overwrite'=>true,'etag'=>$old->getEtag()];
    }
    $linked=$write('forms_link_results_file',$arguments);
    $operation=$linked['operation']['operation_id']??$linked['operation']['id']??null;
    if(!is_string($operation))throw new RuntimeException('Missing original operation identity.');
    for($i=0;$i<6;$i++) {
        $linked=$write('forms_continue_operation',['target_operation_id'=>$operation]);
        if(!in_array($linked['operation']['state']??null,['succeeded','pending'],true))throw new RuntimeException('Continuation outcome requires recovery: '.($linked['operation']['state']??'missing'));
        $state=$linked['data']['state']??null;if($state==='delivered')break;
    }
    $status=$rpc('forms_read_settings',['id'=>$id]);
    if(($status['linkedExport']['state']??null)!=='active')throw new RuntimeException('Public link did not activate.');
    $file=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->get($path);
    $fileId=(int)$file->getId();if(!str_contains($file->getContent(),'Public linked field'))throw new RuntimeException('Initial complete export is missing its field.');
    $checks['persistent_http_initial_link']=true;
    if($mode!=='create') {
        if($fileId!==$originalId)throw new RuntimeException('Initial overwrite replaced the file identity.');
        if($copies) {
            $copy=$linked['data']['file']['backup']??'';
            if(!is_string($copy)||$copy===''||$home->get($copy)->getContent()!==$oldBytes)throw new RuntimeException('Initial private recovery preimage is missing.');
        } else {
            $version=Server::get('OCA\\Files_Versions\\Versions\\IVersionManager')->getVersionFile($user,$file,$oldRevision);
            if(!$version instanceof \OCP\Files\File||$version->getContent()!==$oldBytes)throw new RuntimeException('Initial Versions preimage is missing.');
        }
    }
    $checks['initial_recovery']=$mode;
    $stage='native update then real cron';
    $submission=new \OCA\Forms\Db\Submission();$submission->setFormId($id);$submission->setUserId($uid);$submission->setTimestamp(time());
    $submission=Server::get(\OCA\Forms\Db\SubmissionMapper::class)->insert($submission);
    $answer=new \OCA\Forms\Db\Answer();$answer->setSubmissionId($submission->getId());$answer->setQuestionId($question);$answer->setText('cron-http-answer');
    Server::get(\OCA\Forms\Db\AnswerMapper::class)->insert($answer);
    $jobs=Server::get(\OCP\BackgroundJob\IJobList::class);$jobs->add(\OCA\Mcp\BackgroundJob\FormsLinkedExportJob::class);
    $jobId=(int)$db->executeQuery('SELECT id FROM *PREFIX*jobs WHERE class = ? ORDER BY id DESC',[\OCA\Mcp\BackgroundJob\FormsLinkedExportJob::class])->fetchOne();
    Server::get(\OCP\IUserSession::class)->setUser(null);
    for($i=0;$i<5;$i++) {
        $process=proc_open([PHP_BINARY,'/var/www/html/occ','background-job:execute',(string)$jobId,'--force-execute'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,'/var/www/html');
        if(!is_resource($process))throw new RuntimeException('Cron child unavailable.');
        fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Cron failed.');
        $row=$db->executeQuery('SELECT state,dirty_generation,completed_generation FROM *PREFIX*forms_mcp_linked WHERE form_id = ?',[$id])->fetch();
        if($row['state']!=='active')throw new RuntimeException('Cron association paused.');
        if((int)$row['completed_generation']===(int)$row['dirty_generation'])break;
    }
    $checks['real_cron_updates']=((int)$row['completed_generation']===(int)$row['dirty_generation']);
    if(!$checks['real_cron_updates']||!str_contains($home->get($path)->getContent(),'cron-http-answer'))throw new RuntimeException('Cron did not write the complete new answer.');
    $stage='public unlink retains bytes';
    $before=hash('sha256',$file->getContent());$unlinked=$write('forms_unlink_results_file',['id'=>$id]);
    $status=$rpc('forms_read_settings',['id'=>$id]);
    if(($status['linkedExport']['state']??null)!=='unlinked')throw new RuntimeException('Public unlink did not persist.');
    $fresh=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->get($path);
    if((int)$fresh->getId()!==$fileId||!hash_equals($before,hash('sha256',$fresh->getContent())))throw new RuntimeException('Unlink changed output.');
    $checks['unlink_preserves_file']=true;
    echo json_encode(['ok'=>true,'checks'=>$checks],JSON_THROW_ON_ERROR);
} catch(Throwable $error) {
    echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR);$exitCode=1;
} finally {
    if($backupChanged) {
        if($backupRow===false)$db->executeStatement('DELETE FROM *PREFIX*appconfig WHERE appid = ? AND configkey = ?',['mcp','backup_settings']);
        else $db->executeStatement('UPDATE *PREFIX*appconfig SET configvalue = ?,type = ? WHERE appid = ? AND configkey = ?',[$backupRow['configvalue'],$backupRow['type'],'mcp','backup_settings']);
    }
}
exit($exitCode);

