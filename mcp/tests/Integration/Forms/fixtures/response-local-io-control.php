<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_LOCAL_IO_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';
try{
 $r=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$uid=$r['uid'];$path=$r['path'];
 if(posix_geteuid()!==33||preg_match('/^replacement-http-[a-f0-9]{10}$/D',$uid)!==1||!str_starts_with($path,'/var/www/html/data/'.$uid.'/files/')||realpath($path)!==$path)throw new RuntimeException('Exact synthetic file required.');
 \OCP\Server::get(\OCP\IUserSession::class)->setUser(\OCP\Server::get(\OCP\IUserManager::class)->get($uid));
 $node=\OCP\Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid)->getById($r['file_id'])[0];
 $resolved=$node->getStorage()->getLocalFile($node->getInternalPath());
 if($resolved!==$path||realpath($resolved)!==$path)throw new RuntimeException('Native positive node differs from exact faulted POSIX path.');
 $native=$node->isDeletable()&&$node->getStorage()->isDeletable($node->getInternalPath())&&is_writable($path)&&is_writable(dirname($path));
 if(!$native)throw new RuntimeException('Native writable/deletable positive prerequisite missing.');
 echo json_encode(['ok'=>true,'native_and_posix_writable'=>true,'sticky_dir_root_owned'=>(fileperms(dirname($path))&01000)!==0&&fileowner(dirname($path))===0,'target_root_owned'=>fileowner($path)===0],JSON_THROW_ON_ERROR);
}catch(Throwable $e){echo json_encode(['ok'=>false,'class'=>get_class($e)],JSON_THROW_ON_ERROR);exit(1);}
