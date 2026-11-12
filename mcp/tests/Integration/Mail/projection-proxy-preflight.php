<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
set_exception_handler(static function(Throwable $e):void {fwrite(STDERR,'PROJECTION_PREFLIGHT_FAILED '.get_class($e).' '.basename($e->getFile()).':'.$e->getLine()."\n");exit(1);});
$host=getenv('MCP_IT_PROJECTION_UPSTREAM_HOST');$port=getenv('MCP_IT_PROJECTION_UPSTREAM_PORT');
if(getenv('MCP_IT_CLEAN_MAIL')!=='1'||!is_string($host)||!preg_match('/^mcp-it-[a-z0-9-]+-imap$/D',$host)||$port!=='31143')throw new RuntimeException('Explicit synthetic upstream required');
$dir='/tmp/mcp-projection-preflight-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$p=proc_open([PHP_BINARY,__DIR__.'/projection-imap-proxy.php',$dir,'/tmp/mcp-mail-probe.crt','/tmp/mcp-mail-probe.key',$host,$port],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($p))throw new RuntimeException('Peer unavailable');fclose($pipes[0]);stream_set_timeout($pipes[1],3);
try {
    $listen=json_decode((string)fgets($pipes[1]),true,8,JSON_THROW_ON_ERROR)['port'];
    $c=stream_socket_client('tls://127.0.0.1:'.$listen,$errno,$error,3,STREAM_CLIENT_CONNECT,stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>'127.0.0.1']]));
    if($c===false)throw new RuntimeException('Verified TLS failed');stream_set_timeout($c,3);
    if(!str_starts_with((string)fgets($c),'* OK'))throw new RuntimeException('IMAP greeting missing');
    $token=bin2hex(random_bytes(16));file_put_contents($dir.'/arm.json',json_encode(['kind'=>'STATUS','token'=>$token],JSON_THROW_ON_ERROR));
    fwrite($c,"P1 STATUS INBOX (UIDVALIDITY)\r\n");
    $until=hrtime(true)+3_000_000_000;while(!is_file($dir.'/reached.json')&&hrtime(true)<$until)usleep(1000);
    if(!is_file($dir.'/reached.json'))throw new RuntimeException('Tagged server response did not reach barrier');
    file_put_contents($dir.'/release',$token);$tagged=false;while(($line=fgets($c))!==false){if(preg_match('/^P1 (NO|BAD) /',$line)){$tagged=true;break;}}fclose($c);
    $state=json_decode((string)file_get_contents($dir.'/state.json'),true,16,JSON_THROW_ON_ERROR);
    if(!$tagged||$state['barriers']!==1||$state['released']!==1||$state['timed_out']!==0||$state['stores']!==0)throw new RuntimeException('Barrier preflight failed');
    echo json_encode(['verified_tls'=>true,'real_upstream_greeting'=>true,'unauthenticated_status_refused'=>true,'bounded_barrier_released'=>true,'no_credentials_sent'=>true,'no_store'=>true,'directory'=>$dir],JSON_THROW_ON_ERROR)."\n";
} finally {proc_terminate($p);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);if($error!=='')throw new RuntimeException('Peer diagnostic failed');}
