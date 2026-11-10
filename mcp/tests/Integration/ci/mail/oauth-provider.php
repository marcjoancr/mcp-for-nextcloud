<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
// Disposable loopback TLS provider. Never logs request/response bodies or credential values.
$context=stream_context_create(['ssl'=>['local_cert'=>'/tmp/mcp-mail-probe.crt','local_pk'=>'/tmp/mcp-mail-probe.key']]);
$server=stream_socket_server('tcp://127.0.0.1:443',$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$context);
if(!$server)exit(2);
while($client=@stream_socket_accept($server,3600)){
    stream_set_timeout($client,5);
    if(!@stream_socket_enable_crypto($client,true,STREAM_CRYPTO_METHOD_TLS_SERVER)){fclose($client);continue;}
    $first=fgets($client,4096);$headers=[];
    while(($line=fgets($client,8192))!==false&&trim($line)!==''){
        $parts=explode(':',$line,2);if(count($parts)===2)$headers[strtolower(trim($parts[0]))]=trim($parts[1]);
    }
    $size=filter_var($headers['content-length']??0,FILTER_VALIDATE_INT);
    if($size===false||$size<0||$size>16384){fclose($client);continue;}
    $body='';while(strlen($body)<$size){$chunk=fread($client,$size-strlen($body));if($chunk===false||$chunk==='')break;$body.=$chunk;}
    if(str_starts_with(ltrim($body),'{')||str_contains($headers['content-type']??'','json'))$values=json_decode($body,true);else parse_str($body,$values);
    $host=$headers['host']??'';$grant=$values['grant_type']??'';
    $route=($host==='oauth2.googleapis.com'&&$first==="POST /token HTTP/1.1\r\n")
        ||($host==='login.microsoftonline.com'&&$first==="POST /mcp-fixture/oauth2/v2.0/token HTTP/1.1\r\n");
    $accepted=$route&&in_array($grant,['authorization_code','refresh_token'],true)
        &&($grant==='refresh_token'||($values['code']??'')==='accept-fixture');
    $payload=$accepted?['access_token'=>bin2hex(random_bytes(32)),'refresh_token'=>bin2hex(random_bytes(32)),'expires_in'=>3600]:['error'=>'invalid_grant'];
    $encoded=json_encode($payload,JSON_THROW_ON_ERROR);
    file_put_contents('/tmp/mcp-mail-oauth-counts.log',json_encode(['provider'=>$host==='oauth2.googleapis.com'?'google':'microsoft','grant'=>$grant==='refresh_token'?'refresh':'manual','accepted'=>$accepted])."\n",FILE_APPEND);
    fwrite($client,'HTTP/1.1 '.($accepted?'200 OK':'400 Bad Request')."\r\nContent-Type: application/json\r\nContent-Length: ".strlen($encoded)."\r\nConnection: close\r\n\r\n".$encoded);
    fclose($client);
}
