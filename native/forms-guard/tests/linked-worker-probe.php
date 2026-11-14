<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE',true);
require '/var/www/html/lib/base.php';
use OCP\Server;
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
    $input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    $guard=Server::get(\OCA\Forms\Mcp\Guard::class);$claim=$guard->linkedClaim($input['association_id']);
    if ($claim===null) {throw new RuntimeException('Fixture association cannot be claimed');}
    $uid=$claim['original_owner_uid'];$roots=Server::get(\OCP\Files\IRootFolder::class);
    if (($input['mode']??'')==='wrong_root') {$roots->getUserFolder('guard-other')->getId();}
    try {$guard->initializeLinkedFiles($claim['origin']);} catch(RuntimeException $error) {
        if (($input['mode']??'')!=='wrong_root') {throw $error;}
        echo json_encode(['ok'=>true,'result'=>['initialization_refused'=>true,'null_session'=>Server::get(\OCP\IUserSession::class)->getUser()===null,
            'foreign_root_preserved'=>\OC\Files\Filesystem::getRoot()==='/guard-other/files']],JSON_THROW_ON_ERROR)."\n";exit(0);
    }
    $file=$roots->getUserFolder($uid)->get($claim['last_verified']['path']);
    $cycle=['job_id'=>hash('sha256','forms-job-v1:'.hash('sha256','forms-linked-cycle-v1:'.$claim['association_id'].':'.$claim['dirty_generation'])),'generation'=>$claim['dirty_generation']];
    $claim=$guard->linkedCheckpoint($claim,$cycle);$source=$guard->linkedRead($claim,'snapshot',['id'=>$claim['form_id']]);$calls=0;$stages=[];
    $callback=function()use($guard,$claim,$file,&$calls,&$stages,$input):array {
        if (($input['mode']??'')==='crashed_child') {exit(0);}
        return $guard->deferredFileBoundary($claim['origin'],$file,null,function(string $stage)use(&$stages,$guard,$claim):void {
            $guard->linkedRead($claim,'snapshot',['id'=>$claim['form_id']]);$stages[]=$stage;
        },function()use($file,&$calls):array {
            $calls++;$file->putContent('isolated child verified');
            return ['file_id'=>(int)$file->getId(),'path'=>$file->getName(),'etag'=>(string)$file->getEtag(),'size'=>(int)$file->getSize(),'sha256'=>hash('sha256',$file->getContent())];
        });
    };
    $refused=false;
    try {$guard->syncLinkedFile($claim['origin'],$claim['association_id'],$claim['version'],$source['export_revision'],hash('sha256','forms-linked-write-v1:'.$claim['association_id'].':'.$cycle['generation']),['lease_token'=>$claim['lease_token'],'active_cycle'=>$cycle,'destination'=>[]],$callback);}catch(Throwable $error){if (($input['mode']??'')!=='wrong_root') {throw $error;}$refused=true;}
    $checks=['null_session'=>Server::get(\OCP\IUserSession::class)->getUser()===null];
    if (($input['mode']??'')==='wrong_root') {$checks+=['refused'=>$refused,'no_sink'=>$calls===0,'foreign_root_preserved'=>\OC\Files\Filesystem::getRoot()==='/guard-other/files'];}
    else {$checks+=['one_sink'=>$calls===1,'content_stage'=>in_array('content',$stages,true),'root'=>\OC\Files\Filesystem::getRoot()==='/'.$uid.'/files'];}
    echo json_encode(['ok'=>true,'result'=>$checks],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $error) {
    echo json_encode(['ok'=>false,'error'=>get_class($error).': '.$error->getMessage()],JSON_THROW_ON_ERROR)."\n";exit(1);
}
