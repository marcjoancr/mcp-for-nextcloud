<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// Synthetic TLS proxy. Never records commands, credentials, literals or exception messages.
ini_set('zend.exception_ignore_args','1');
set_exception_handler(static function(Throwable $error):void {fwrite(STDERR,'PROJECTION_PROXY_FAILED '.get_class($error)."\n");exit(1);});
[$script,$directory,$certificate,$key,$upstreamHost,$upstreamPort]=$argv;
if(getenv('MCP_IT_CLEAN_MAIL')!=='1'||!is_dir($directory)||is_link($directory)||!is_readable($certificate)||!is_readable($key)||!ctype_digit($upstreamPort))throw new RuntimeException('Closed synthetic proxy arguments required');
$context=stream_context_create(['ssl'=>['local_cert'=>$certificate,'local_pk'=>$key,'verify_peer'=>false,'allow_self_signed'=>false,'disable_compression'=>true]]);
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$context);
if($server===false)throw new RuntimeException('Loopback proxy unavailable');
$state=['connections'=>0,'status'=>0,'stores'=>0,'barriers'=>0,'released'=>0,'timed_out'=>0];
$save=static function()use($directory,&$state):void {file_put_contents($directory.'/state.json',json_encode($state,JSON_THROW_ON_ERROR)."\n",LOCK_EX);};$save();
echo json_encode(['port'=>(int)substr(strrchr(stream_socket_get_name($server,false),':'),1)],JSON_THROW_ON_ERROR)."\n";flush();
$channels=[];$buffers=[];$pending=[];$used=[];
$write=static function($stream,string $bytes):void {while($bytes!==''){$n=fwrite($stream,$bytes);if(!is_int($n)||$n<1)throw new RuntimeException('Proxy channel closed');$bytes=substr($bytes,$n);}};
while(true){
    $read=[$server];foreach($channels as$pair){$read[]=$pair[0];$read[]=$pair[1];}$writeReady=null;$except=null;
    if(stream_select($read,$writeReady,$except,1)===false)break;
    foreach($read as$stream){
        if($stream===$server){
            $client=stream_socket_accept($server,1);if($client===false)continue;
            stream_set_timeout($client,3);
            if(!stream_socket_enable_crypto($client,true,STREAM_CRYPTO_METHOD_TLS_SERVER)){fclose($client);continue;}
            $upstream=stream_socket_client('tcp://'.$upstreamHost.':'.$upstreamPort,$errno,$error,3);
            if($upstream===false){fclose($client);throw new RuntimeException('Synthetic upstream unavailable');}
            stream_set_timeout($upstream,3);$id=(int)$client;$channels[$id]=[$client,$upstream];$buffers[$id]=['',''];$pending[$id]=[];++$state['connections'];$save();continue;
        }
        foreach($channels as$id=>$pair){
            $side=$stream===$pair[0]?0:($stream===$pair[1]?1:-1);if($side<0)continue;
            $bytes=fread($stream,65536);
            if($bytes===false||$bytes===''){if(feof($stream)){fclose($pair[0]);fclose($pair[1]);unset($channels[$id],$buffers[$id],$pending[$id]);}break;}
            $buffers[$id][$side].=$bytes;
            if(strlen($buffers[$id][$side])>2*1024*1024)throw new RuntimeException('Synthetic proxy line bound exceeded');
            while(($end=strpos($buffers[$id][$side],"\n"))!==false){
                $line=substr($buffers[$id][$side],0,$end+1);$buffers[$id][$side]=substr($buffers[$id][$side],$end+1);
                if($side===0&&preg_match('/^(\S+) (STATUS|UID STORE) /i',$line,$match)){
                    $kind=strtoupper($match[2]);++$state[$kind==='STATUS'?'status':'stores'];$save();
                    $arm=is_file($directory.'/arm.json')?json_decode((string)file_get_contents($directory.'/arm.json'),true,8,JSON_THROW_ON_ERROR):[];
                    $token=$arm['token']??'';
                    if(($arm['kind']??null)===$kind&&is_string($token)&&preg_match('/^[a-f0-9]{32}$/D',$token)&&!isset($used[$token])){$pending[$id][$match[1]]=$token;$used[$token]=true;}
                }
                if($side===1&&preg_match('/^(\S+) (OK|NO|BAD)(?: |\r)/i',$line,$match)&&isset($pending[$id][$match[1]])){
                    $token=$pending[$id][$match[1]];unset($pending[$id][$match[1]]);++$state['barriers'];$save();
                    file_put_contents($directory.'/reached.json',json_encode(['token'=>$token],JSON_THROW_ON_ERROR)."\n",LOCK_EX);
                    $deadline=hrtime(true)+8_000_000_000;$released=false;
                    do{if(is_file($directory.'/release')&&trim((string)file_get_contents($directory.'/release'))===$token){$released=true;break;}usleep(1000);}while(hrtime(true)<$deadline);
                    ++$state[$released?'released':'timed_out'];$save();if(!$released)throw new RuntimeException('Synthetic barrier expired');
                }
                $write($pair[1-$side],$line);
            }
            break;
        }
    }
}
