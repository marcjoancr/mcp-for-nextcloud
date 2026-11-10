<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Disposable authentication-only TLS fixture. Never accepts an envelope or message body, never logs credentials.
$protocol=$argv[1];$port=$protocol==='imap'?33493:33465;
$ctx=stream_context_create(['ssl'=>['local_cert'=>'/tmp/mcp-mail-probe.crt','local_pk'=>'/tmp/mcp-mail-probe.key']]);
$server=stream_socket_server('tcp://127.0.0.1:'.$port,$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$ctx);
if(!$server)exit(2);
while($client=@stream_socket_accept($server,3600)){
 stream_set_timeout($client,5);
 if(!@stream_socket_enable_crypto($client,true,STREAM_CRYPTO_METHOD_TLS_SERVER)){fclose($client);continue;}
 fwrite($client,$protocol==='imap'?"* OK fixture ready\r\n":"220 fixture ESMTP\r\n");
 while(($line=fgets($client,4096))!==false){
  $parts=explode(' ',trim($line),3);$tag=$protocol==='imap'?$parts[0]:'';$cmd=strtoupper($parts[$protocol==='imap'?1:0]??'');
  file_put_contents('/tmp/mcp-mail-probe-'.$protocol.'.log',$cmd."\n",FILE_APPEND);
  if($protocol==='imap'){
   if($cmd==='CAPABILITY')fwrite($client,"* CAPABILITY IMAP4rev1 AUTH=PLAIN AUTH=XOAUTH2 SASL-IR ID\r\n$tag OK capability\r\n");
   elseif($cmd==='AUTHENTICATE'){
    $auth=explode(' ',$parts[2]??'',2);$encoded=$auth[1]??null;
    if($encoded===null){fwrite($client,"+ \r\n");$encoded=trim((string)fgets($client,4096));}
    $decoded=(string)base64_decode($encoded,true);$fields=explode("\0",$decoded);$ok=end($fields)==='mcp-it-probe-password';
    if(strtoupper($auth[0])==='XOAUTH2')$ok=is_file('/tmp/mcp-mail-probe-oauth-sync')&&str_contains($decoded,"\1auth=Bearer ");
    fwrite($client,$tag.($ok?" OK authenticated\r\n":" NO denied\r\n"));
   }elseif($cmd==='LOGIN')fwrite($client,$tag.(str_contains($parts[2]??'','mcp-it-probe-password')?" OK authenticated\r\n":" NO denied\r\n"));
   elseif($cmd==='LOGOUT'){fwrite($client,"* BYE closing\r\n$tag OK logout\r\n");break;}
   elseif(in_array($cmd,['SELECT','EXAMINE','APPEND','STORE','EXPUNGE','DELETE'],true))fwrite($client,"$tag BAD no mailbox operations\r\n");
   else fwrite($client,"$tag OK fixture\r\n");
  }else{
   if($cmd==='EHLO'&&@file_get_contents('/tmp/mcp-mail-probe-mode')==='delay')sleep(15);
   if($cmd==='EHLO')fwrite($client,"250-fixture\r\n250 AUTH PLAIN LOGIN\r\n");
   elseif($cmd==='AUTH'){
    $auth=explode(' ',trim(substr($line,5)),2);$encoded=$auth[1]??null;
    if(strtoupper($auth[0])==='LOGIN'){
     if($encoded===null){fwrite($client,"334 VXNlcm5hbWU6\r\n");fgets($client,4096);}
     fwrite($client,"334 UGFzc3dvcmQ6\r\n");$ok=base64_decode(trim((string)fgets($client,4096)),true)==='mcp-it-probe-password';
    }else{
     if($encoded===null){fwrite($client,"334 \r\n");$encoded=trim((string)fgets($client,4096));}
     $fields=explode("\0",(string)base64_decode($encoded,true));$ok=end($fields)==='mcp-it-probe-password';
    }
    $ok=$ok&&@file_get_contents('/tmp/mcp-mail-probe-mode')!=='reject';
    fwrite($client,$ok?"235 authenticated\r\n":"535 denied\r\n");
   }elseif($cmd==='QUIT'){fwrite($client,"221 bye\r\n");break;}
   else fwrite($client,"554 authentication only; no delivery\r\n");
  }
 }
 fclose($client);
}
