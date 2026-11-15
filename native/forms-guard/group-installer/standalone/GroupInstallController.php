<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// PID1 never bootstraps Nextcloud and never accepts a command/path from its caller.
if(PHP_SAPI!=='cli'||getmypid()!==1||$argc!==1){exit(2);}
require __DIR__.'/GroupInstallProtocol.php';
$count=0;
while(($line=fgets(STDIN,1048578))!==false){
 try{
  $r=\McpFormsServerExtension\GroupInstallProtocol::request($line,++$count);
  if(!is_array($r['profile']))throw new RuntimeException('Controller profile refused');
  $profileFile='/profiles/'.sprintf('%02d.json',$count);$profileBytes=json_encode($r['profile'],JSON_THROW_ON_ERROR);if(strlen($profileBytes)>1048576)throw new RuntimeException('Controller profile bound');
  $oldMask=umask(0077);try{$handle=fopen($profileFile,'x');if($handle===false||fwrite($handle,$profileBytes)!==strlen($profileBytes)||!fflush($handle)||!fsync($handle))throw new RuntimeException('Controller profile unavailable');fclose($handle);if(!chown($profileFile,33)||!chgrp($profileFile,33))throw new RuntimeException('Controller profile owner');}finally{umask($oldMask);}
  // A fresh process per phase preserves native classic-request static lifetime.
  $command=[PHP_BINARY,__DIR__.'/GroupInstallChild.php',sprintf('%02d.json',$count),$r['phase']];
  $p=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['PATH'=>'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin']);if(!is_resource($p))throw new RuntimeException('Controller child unavailable');fclose($pipes[0]);
  stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$out='';$bytes=0;$end=microtime(true)+120;$st=null;
  do{foreach([1,2]as$i){$part=stream_get_contents($pipes[$i]);$bytes+=strlen($part);if($i===1)$out.=$part;}if($bytes>16384||microtime(true)>$end){proc_terminate($p,15);usleep(100000);$last=proc_get_status($p);if($last['running'])proc_terminate($p,9);throw new RuntimeException('Controller child uncertain');}$st=proc_get_status($p);if($st['running'])usleep(10000);}while($st['running']);
  foreach([1,2]as$i){$part=stream_get_contents($pipes[$i]);$bytes+=strlen($part);if($i===1)$out.=$part;fclose($pipes[$i]);}proc_close($p);
  if($bytes>16384)throw new RuntimeException('Controller phase failed');\McpFormsServerExtension\GroupInstallProtocol::response($out,$st['exitcode'],$r['phase']);
  $extra=$r['phase']==='snapshot'?array_intersect_key(json_decode($out,true),array_flip(['data_digest','data_count','data_bytes'])):[];
  echo json_encode(['ok'=>true,'sequence'=>$count,'stage'=>$r['phase']]+$extra,JSON_THROW_ON_ERROR),"\n";fflush(STDOUT);
 }catch(Throwable){echo json_encode(['ok'=>false,'sequence'=>$count,'stage'=>'hold']),"\n";exit(1);}
}
exit(0);
