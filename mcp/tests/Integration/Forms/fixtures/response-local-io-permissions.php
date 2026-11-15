<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_LOCAL_IO_BOUNDARY')!=='1')exit(2);
$injectedFailure=false;
try {
 $r=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$path=$r['path'];$uid=$r['uid'];
 if(posix_geteuid()!==0||preg_match('/^replacement-http-[a-f0-9]{10}$/D',$uid)!==1||!str_starts_with($path,'/var/www/html/data/'.$uid.'/files/')||realpath($path)!==$path||is_link($path)||!is_file($path))throw new RuntimeException('Exact synthetic source required.');
 $parent=dirname($path);if(realpath($parent)!==$parent||is_link($parent))throw new RuntimeException('Exact synthetic parent required.');
 $metadata=static function(string $p):array{clearstatcache(true,$p);return array_intersect_key(lstat($p),array_flip(['uid','gid','mode','dev','ino']));};
 $file=$metadata($path);$dir=$metadata($parent);
 $current=['path'=>$path,'uid'=>$uid,'file'=>$file,'dir'=>$dir];
 $restore=static function(array $proof)use($path,$parent,$uid,$metadata):void{
  if(($proof['path']??null)!==$path||($proof['uid']??null)!==$uid)throw new RuntimeException('OS restore target changed.');
  foreach([[$path,$proof['file']],[$parent,$proof['dir']]]as[$p,$s]){
   $now=$metadata($p);if($now['dev']!==$s['dev']||$now['ino']!==$s['ino'])throw new RuntimeException('OS restore inode changed.');
   if(!chown($p,$s['uid'])||!chgrp($p,$s['gid'])||!chmod($p,$s['mode']&07777)||$metadata($p)!==$s)throw new RuntimeException('Exact OS preimage restore failed.');
  }
 };
 if($r['mode']==='capture') {
  if($file['uid']!==33||$dir['uid']!==33)throw new RuntimeException('Unexpected source OS preimage.');
  echo json_encode(['ok'=>true,'preimage'=>$current],JSON_THROW_ON_ERROR);
 }elseif($r['mode']==='apply') {
  $proof=$r['preimage'];if($proof!==$current||$file['uid']!==33||$dir['uid']!==33)throw new RuntimeException('Captured OS preimage changed before injection.');
  $success=false;try {
   if(!chown($parent,0)||!chmod($parent,01777))throw new RuntimeException('Exact parent OS injection failed.');
   if(($r['fail_after_parent']??false)===true){$injectedFailure=true;throw new RuntimeException('Disposable partial injection failure.');}
   if(!chown($path,0)||!chmod($path,0666))throw new RuntimeException('Exact file OS injection failed.');
   $success=true;echo json_encode(['ok'=>true],JSON_THROW_ON_ERROR);
  }finally{if(!$success)$restore($proof);}
 }elseif($r['mode']==='restore') {
  $restore($r['preimage']);echo json_encode(['ok'=>true,'preimage_restored'=>true],JSON_THROW_ON_ERROR);
 }else throw new RuntimeException('Unsupported fixture mode.');
}catch(Throwable $e){echo json_encode(['ok'=>false,'class'=>get_class($e),'injected_failure'=>$injectedFailure],JSON_THROW_ON_ERROR);exit(1);}
