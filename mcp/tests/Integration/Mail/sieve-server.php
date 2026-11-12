<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// Synthetic store only. No mail interpreter, forwarding, filesystem script persistence or delivery backend.
$mode=$argv[1]??'normal';$dir=getenv('MCP_IT_DELIVERY_DIRECTORY');if($dir!=='/tmp/mcp-mail-delivery-fixture')exit(2);
$ctx=stream_context_create(['ssl'=>['local_cert'=>$dir.'/certificate.pem','local_pk'=>$dir.'/key.pem']]);$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$ctx);if(!$server)exit(2);echo json_encode(['port'=>(int)substr(strrchr(stream_socket_get_name($server,false),':'),1)])."\n";flush();
$scripts=['original'=>"# retained SYNTHETIC_PRIVATE_PROGRAM\r\n"];if($mode==='opaque')$scripts['original']='require ["reject"]; reject "SYNTHETIC_PRIVATE_PROGRAM";';if($mode==='collision')$scripts['mcp-'.str_repeat('a',64)]='# existing candidate';if($mode==='read_absence_private'){$marker="### Nextcloud Mail: Vacation Responder ### DON'T EDIT ###";$scripts['original']=$marker."\r\n# DATA: ".json_encode(['version'=>1,'enabled'=>true,'start'=>'invalid-date','end'=>null,'subject'=>'PRIVATE_READ_CANARY','message'=>'PRIVATE_READ_CANARY'])."\r\n".$marker;}
if($mode==='read_filter_private'){$marker="### Nextcloud Mail: Filters ### DON'T EDIT ###";$scripts['original']=$marker."\r\n# FILTER: {PRIVATE_READ_CANARY\r\n".$marker;}
$original=$scripts['original'];$active='original';$counts=[];$faulted=false;$connections=0;$deadline=microtime(true)+180;
while(microtime(true)<$deadline){$read=[$server,STDIN];$write=[];$except=[];if(stream_select($read,$write,$except,2)===false)break;if(in_array(STDIN,$read,true)&&fread(STDIN,1)==='')break;if(!in_array($server,$read,true))continue;$c=stream_socket_accept($server,1);if(!$c)continue;stream_set_timeout($c,3);if(!@stream_socket_enable_crypto($c,true,STREAM_CRYPTO_METHOD_TLS_SERVER)){fclose($c);continue;}++$connections;
 $send=static function(string $s)use($c):void{$off=0;while($off<strlen($s)){$n=@fwrite($c,substr($s,$off,8192));if(!$n)return;$off+=$n;}};
 $caps='"IMPLEMENTATION" "synthetic"'."\r\n".'"SASL" "PLAIN"'."\r\n".'"SIEVE" "fileinto imap4flags vacation date relational variables copy"'."\r\nOK\r\n";$send($caps);
 while(($line=@fgets($c,8193))!==false){$cmd=strtoupper(rtrim(strtok($line,' ')));$counts[$cmd]=($counts[$cmd]??0)+1;
  if($cmd==='CAPABILITY')$send($caps);
  elseif($cmd==='AUTHENTICATE'){if(preg_match('/\{([0-9]+)\+?\}\r\n$/D',$line,$m)){if((int)$m[1]>8192)break;$remaining=(int)$m[1]+2;while($remaining>0){$s=fread($c,$remaining);if($s==='')break 2;$remaining-=strlen($s);}}$send("OK\r\n");}
  elseif($cmd==='LISTSCRIPTS'){foreach($scripts as$name=>$script)$send('"'.$name.'"'.($name===$active?' ACTIVE':'')."\r\n");$send("OK\r\n");}
  elseif($cmd==='GETSCRIPT'){
   if($mode==='failed_readback'&&str_starts_with($active,'mcp-')&&!$faulted){$faulted=true;break;}
   preg_match('/"([^"]+)"/',$line,$m);$script=$scripts[$m[1]]??null;if($script===null){$send("NO\r\n");continue;}$send('{'.strlen($script)."}\r\n".$script."\r\nOK\r\n");if($mode==='external_after_readback'&&str_starts_with($active,'mcp-')&&!$faulted){$active='original';$faulted=true;}
  }elseif($cmd==='PUTSCRIPT'){
   if(preg_match('/"([^"]+)" \{([0-9]+)\+\}\r\n$/D',$line,$m)!==1||(int)$m[2]>262144)break;$n=(int)$m[2];$script='';while(strlen($script)<$n){$s=fread($c,min(8192,$n-strlen($script)));if($s==='')break 2;$script.=$s;}fread($c,2);$scripts[$m[1]]=$script;
   if($mode==='lost_put'&&!$faulted){$faulted=true;break;}$send("OK\r\n");if($mode==='external_before_active')$scripts['original']="# external change\r\n";
  }elseif($cmd==='SETACTIVE'){
   preg_match('/"([^"]+)"/',$line,$m);if(!isset($scripts[$m[1]])){$send("NO\r\n");continue;}$active=$m[1];if($mode==='lost_active'&&!$faulted){$faulted=true;break;}$send("OK\r\n");
  }elseif($cmd==='LOGOUT'){$send("OK\r\n");break;}else{$send("NO\r\n");break;}
 }fclose($c);
}
fclose($server);echo json_encode(['connections'=>$connections,'commands'=>$counts,'original_retained'=>isset($scripts['original']),'original_unchanged'=>($scripts['original']??null)===$original,'active_candidate'=>str_starts_with($active,'mcp-'),'candidate_count'=>count($scripts)-1,'no_delivery_backend'=>true])."\n";
