<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE',true);
require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Forms\Mcp\Service\FormsRevisionFence;
function requestDav(string $uid,string $password,string $method,string $path,string $body='',array $headers=[]): array {
    $curl=curl_init('http://127.0.0.1/remote.php/dav/comments/'.$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_USERPWD=>$uid.':'.$password,
        CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>array_merge(['OCS-APIRequest: true'],$headers),CURLOPT_TIMEOUT=>15]);
    $response=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_HTTP_CODE);$error=curl_error($curl);curl_close($curl);
    if ($response===false) {throw new RuntimeException($error);}
    return ['code'=>$code,'body'=>$response];
}
try {
    $input=json_decode(stream_get_contents(STDIN),true)??[];
    if (($input['mode']??'')==='child') {
        echo json_encode(requestDav($input['uid'],$input['password'],$input['method'],$input['path'],$input['body'],$input['headers']));exit;
    }
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    $uid='comment-'.bin2hex(random_bytes(6));$password=bin2hex(random_bytes(24));
    $users=Server::get(\OCP\IUserManager::class);$user=$users->createUser($uid,$password);
    Server::get(\OCP\IUserSession::class)->setUser($user);
    $api=(new \OCA\Forms\AppInfo\Application())->getContainer()->get(\OCA\Forms\Controller\ApiController::class);
    $form=$api->newForm()->getData();$id=$form['id'];
    $api->updateForm($id,['allowComments'=>true]);
    $db=Server::get(\OCP\IDBConnection::class);$manager=Server::get(\OCP\Comments\ICommentsManager::class);
    $fence=Server::get(FormsRevisionFence::class);$revision=(int)$fence->revision();
    $add=requestDav($uid,$password,'POST','forms/'.$id,json_encode(['actorType'=>'users','verb'=>'comment','message'=>'DAV original']),['Content-Type: application/json']);
    $commentId=(string)$db->executeQuery('SELECT MAX(id) FROM *PREFIX*comments WHERE object_type = ? AND object_id = ?',['forms',(string)$id])->fetchOne();
    $marker=$db->executeQuery('SELECT marker_datetime FROM *PREFIX*comments_read_markers WHERE object_type = ? AND object_id = ? AND user_id = ?',['forms',(string)$id,$uid])->fetchOne();
    $checks=['dav_add'=>$add['code']===201,'native_revision'=>(int)$fence->revision()===$revision+1,'poster_marker'=>$marker!==false];
    $readRevision=$fence->revision();
    $propfind=requestDav($uid,$password,'PROPFIND','forms/'.$id,'',['Depth: 1']);
    $report=requestDav($uid,$password,'REPORT','forms/'.$id,'<?xml version="1.0"?><oc:filter-comments xmlns:oc="http://owncloud.org/ns"><oc:limit>50</oc:limit><oc:offset>0</oc:offset></oc:filter-comments>',['Content-Type: application/xml']);
    $checks['reads_status']=$propfind['code']===207 && $report['code']===207;
    $checks['reads_no_marker']=$marker===$db->executeQuery('SELECT marker_datetime FROM *PREFIX*comments_read_markers WHERE object_type = ? AND object_id = ? AND user_id = ?',['forms',(string)$id,$uid])->fetchOne();
    $checks['reads_no_revision']=$readRevision===$fence->revision();
    $patch='<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:set><d:prop><oc:message>DAV changed</oc:message></d:prop></d:set></d:propertyupdate>';
    $edit=requestDav($uid,$password,'PROPPATCH','forms/'.$id.'/'.$commentId,$patch,['Content-Type: application/xml']);
    $checks['dav_edit']=$edit['code']===207 && $db->executeQuery('SELECT message FROM *PREFIX*comments WHERE id = ?',[(int)$commentId])->fetchOne()==='DAV changed';
    foreach (['add','edit','delete'] as $case) {
        $process=null;$pipes=[];$waited=false;
        $fence->transaction(function()use($case,$uid,$password,$id,$commentId,$patch,$db,&$process,&$pipes,&$waited) {
            $child=['mode'=>'child','uid'=>$uid,'password'=>$password,'method'=>match($case){'add'=>'POST','edit'=>'PROPPATCH','delete'=>'DELETE'},
                'path'=>'forms/'.$id.($case==='add'?'':'/'.$commentId),'body'=>$case==='add'?json_encode(['actorType'=>'users','verb'=>'comment','message'=>'must refuse']):($case==='edit'?$patch:''),
                'headers'=>[$case==='add'?'Content-Type: application/json':'Content-Type: application/xml']];
            $process=proc_open([PHP_BINARY,__FILE__],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            fwrite($pipes[0],json_encode($child));fclose($pipes[0]);
            $deadline=microtime(true)+10;
            do {
                $waited=(int)$db->executeQuery("SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = 1179603533 AND objid = 1 AND objsubid = 2 AND NOT granted")->fetchOne()>0;
                if ($waited) {break;}
                usleep(100000);
            } while (microtime(true)<$deadline);
            if (!$waited) {throw new RuntimeException('DAV mutation did not reach the actual native advisory-lock wait');}
            if ($case==='edit') {$db->executeStatement('UPDATE *PREFIX*comments SET actor_id = ? WHERE id = ?',['guard-owner',(int)$commentId]);}
            else {$db->executeStatement('UPDATE *PREFIX*forms_v2_forms SET allow_comments = ? WHERE id = ?',[0,$id]);}
        });
        $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        $result=json_decode($out,true,64,JSON_THROW_ON_ERROR);
        $checks[$case.'_waits']=$waited;
        $checks[$case.'_refused']=$result['code']>=400 || ($case==='edit' && str_contains($result['body'],'500 Internal Server Error'));
        $db->executeStatement('UPDATE *PREFIX*forms_v2_forms SET allow_comments = ? WHERE id = ?',[1,$id]);
        $db->executeStatement('UPDATE *PREFIX*comments SET actor_id = ? WHERE id = ?',[$uid,(int)$commentId]);
    }
    $delete=requestDav($uid,$password,'DELETE','forms/'.$id.'/'.$commentId);
    $checks['dav_delete']=$delete['code']===204 && $db->executeQuery('SELECT id FROM *PREFIX*comments WHERE id = ?',[(int)$commentId])->fetchOne()===false;
    echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks,'codes'=>[$add['code'],$propfind['code'],$report['code'],$edit['code'],$delete['code']]],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $error) {echo json_encode(['ok'=>false,'error'=>$error->getMessage(),'trace'=>$error->getTraceAsString()]);exit(1);}
