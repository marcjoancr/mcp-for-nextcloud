<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')exit(2);
$stage='bootstrap';
try {
    $input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    if(($input['mode']??null)==='verify') {
        define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
        \OC_App::loadApps();
        $session=\OCP\Server::get(\OCP\IUserSession::class);
        if($session->getUser()!==null)throw new RuntimeException('Verification must start with a null session.');
        $e=$input['evidence']??[];$uid=$e['uid']??'';
        if(preg_match('/^link-http-[a-f0-9]{10}$/D',$uid)!==1||($e['path']??null)!=='public-linked-results.csv')throw new RuntimeException('Unexpected fixture identity.');
        $stage='fresh isolated Versions verification';
        $file=\OCP\Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->get($e['path']);
        if(!$file instanceof \OCP\Files\File||(int)$file->getId()!==$e['file_id']||\OC\Files\Filesystem::getRoot()!=='/'.$uid.'/files')throw new RuntimeException('File or root belongs to a different actor.');
        $manager=\OCP\Server::get(\OCA\Files_Versions\Versions\IVersionManager::class);
        $user=\OCP\Server::get(\OCP\IUserManager::class)->get($uid);
        $version=$manager->getVersionFile($user,$file,$e['revision']);
        if(!$version instanceof \OCP\Files\File||!hash_equals($e['seed_sha256'],hash('sha256',$version->getContent())))throw new RuntimeException('Seeded old bytes changed after both actors wrote.');
        if(!hash_equals($e['final_sha256'],hash('sha256',$file->getContent()))||!str_contains($file->getContent(),'cron-http-answer-'.$uid)||$manager->getVersionsForFile($user,$file)===[]||$session->getUser()!==null)throw new RuntimeException('Current output, native entity or null session changed.');
        echo json_encode(['ok'=>true,'checks'=>['seeded_version_after_both_writes'=>true,'own_output'=>true,'native_entity'=>true,'null_session'=>true]],JSON_THROW_ON_ERROR);exit;
    }
    if(($input['mode']??null)!=='suite')throw new RuntimeException('Unexpected fixture mode.');
    $invoke=static function(string $path,array $request):array {
        $p=proc_open([PHP_BINARY,$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($p))throw new RuntimeException('Fixture child unavailable.');
        fwrite($pipes[0],json_encode($request,JSON_THROW_ON_ERROR));fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
        if($exit!==0)throw new RuntimeException('Fixture child failed: '.$out);
        $r=json_decode($out,true,32,JSON_THROW_ON_ERROR);
        if(($r['ok']??false)!==true)throw new RuntimeException('Unverified fixture outcome.');
        return $r;
    };
    $stage='two actual MCP OAuth initial delivery cron and unlink journeys';
    $source=getenv('MCP_FORMS_LINKED_PUBLIC_PROBE')?:__DIR__.'/linked-public-http.php';$evidence=[];
    for($i=0;$i<2;$i++) {
        $r=$invoke($source,['mode'=>'versions_evidence']);
        foreach(['persistent_http_initial_link','real_cron_updates','unlink_preserves_file'] as $check)if(($r['checks'][$check]??false)!==true)throw new RuntimeException('Incomplete public MCP journey.');
        $evidence[]=$r['verification'];
    }
    if($evidence[0]['uid']===$evidence[1]['uid']||$evidence[0]['path']!==$evidence[1]['path']||$evidence[0]['seed_sha256']===$evidence[1]['seed_sha256'])throw new RuntimeException('Fixture actors must have same relative path and distinct old bytes.');
    $stage='fresh final verification children after both actors wrote';
    foreach($evidence as $e) {
        $r=$invoke(__FILE__,['mode'=>'verify','evidence'=>$e]);
        foreach(['seeded_version_after_both_writes','own_output','native_entity','null_session'] as $check)if(($r['checks'][$check]??false)!==true)throw new RuntimeException('Final cross-actor proof failed.');
    }
    echo json_encode(['ok'=>true,'checks'=>['actual_mcp_journeys'=>2,'verification_children'=>2,'same_relative_path'=>true,'distinct_preimages'=>true,'final_versions_and_outputs'=>true]],JSON_THROW_ON_ERROR);
} catch(Throwable $e) {echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$e->getMessage()],JSON_THROW_ON_ERROR);exit(1);}
