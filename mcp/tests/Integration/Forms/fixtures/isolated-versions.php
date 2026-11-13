<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
if(getenv('MCP_FORMS_VERSIONS_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
use OCP\Server;
$stage='bootstrap';
try {
    \OC_App::loadApps();
    $session=Server::get(\OCP\IUserSession::class);
    if($session->getUser()!==null)throw new RuntimeException('A clean null-session process is required.');
    $root=Server::get(\OCP\Files\IRootFolder::class);
    $users=Server::get(\OCP\IUserManager::class);
    $versions=Server::get(\OCA\Files_Versions\Versions\IVersionManager::class);
    $input=json_decode(stream_get_contents(STDIN,4097),true,16,JSON_THROW_ON_ERROR);
    if(($input['mode']??null)==='child') {
        $uid=$input['uid']??'';$path=$input['path']??'';
        if(preg_match('/^guard-vers-[a-f0-9]{12}-[ab]$/D',$uid)!==1||preg_match('#^/Forms-versions-[a-f0-9]{12}/results.csv$#D',$path)!==1)throw new RuntimeException('Invalid fixture identity.');
        $stage='isolated root';
        $initial=\OC\Files\Filesystem::getRoot();
        if($initial!==null&&$initial!=='/'.$uid.'/files')throw new RuntimeException('Foreign root initialized before origin.');
        $file=$root->getUserFolder($uid)->get($path);
        if(!$file instanceof \OCP\Files\File)throw new RuntimeException('No owned fixture file.');
        $ownedRoot='/'.$uid.'/files';
        if(\OC\Files\Filesystem::getRoot()!==$ownedRoot||\OC\Files\Filesystem::getView()->getOwner('')!==$uid)throw new RuntimeException('Native default view differs from origin.');
        $before=$file->getContent();$revision=$versions->getRevision($file);$fileId=$file->getId();
        $stage='native overwrite';
        $file->putContent('Updated '.$uid);
        if($session->getUser()!==null||\OC\Files\Filesystem::getRoot()!==$ownedRoot)throw new RuntimeException('Origin process context changed.');
        $stage='native old version';
        $old=$versions->getVersionFile($users->get($uid),$file,$revision);
        if(!hash_equals(hash('sha256',$before),hash('sha256',$old->getContent())))throw new RuntimeException('Native version has wrong bytes.');
        $listed=$versions->getVersionsForFile($users->get($uid),$file);
        if($listed===[]||$file->getId()!==$fileId)throw new RuntimeException('Native version entity or output identity missing.');
        echo json_encode(['ok'=>true,'checks'=>['null_session'=>true,'origin_root'=>true,'old_bytes'=>true,'entity'=>true,'file_id'=>true]],JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if(($input['mode']??null)!=='suite')throw new RuntimeException('Invalid fixture mode.');
    $nonce=bin2hex(random_bytes(6));$path='/Forms-versions-'.$nonce.'/results.csv';$actors=[];$before=[];
    $stage='owned fixtures';
    foreach(['a','b'] as $suffix) {
        $uid='guard-vers-'.$nonce.'-'.$suffix;
        if($users->get($uid)!==null)throw new RuntimeException('Fixture UID collision.');
        $user=$users->createUser($uid,bin2hex(random_bytes(24)));if(!$user)throw new RuntimeException('Fixture actor unavailable.');
        $home=$root->getUserFolder($uid);$folder=$home->newFolder('Forms-versions-'.$nonce);$file=$folder->newFile('results.csv');
        $file->putContent('Original '.$uid);$file->touch(time()-120);
        $actors[]=$uid;$before[$uid]=hash('sha256',$file->getContent());
    }
    $stage='separate children';$results=[];
    foreach($actors as $uid) {
        $process=proc_open([PHP_BINARY,__FILE__],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Child did not start.');
        fwrite($pipes[0],json_encode(['mode'=>'child','uid'=>$uid,'path'=>$path],JSON_THROW_ON_ERROR));fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        if($exit!==0)throw new RuntimeException('Isolated child failed: '.$out);
        $result=json_decode($out,true,16,JSON_THROW_ON_ERROR);if(($result['ok']??false)!==true)throw new RuntimeException('Child not verified.');
        $results[]=$result;
    }
    $stage='cross-actor verification';
    foreach($actors as $uid)if($root->getUserFolder($uid)->get($path)->getContent()!=='Updated '.$uid)throw new RuntimeException('Another actor output changed.');
    echo json_encode(['ok'=>true,'checks'=>['separate_children'=>count($results),'null_session'=>true,'correct_versions'=>true,'entities'=>true,'cross_actor_isolation'=>true]],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $error) {
    echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$error::class,'message'=>$error->getMessage()],JSON_THROW_ON_ERROR)."\n";exit(1);
}
