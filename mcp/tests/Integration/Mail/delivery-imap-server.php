<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// Synthetic APPEND ambiguity peer, including BINARY advertisement which triggers stock Horde replay.
$mode=$argv[1]??'lost_ack';if(!in_array($mode,['lost_ack','oversized_literal','wrong_appenduid','missing_appenduid','selected_generation','uid_after_literal','duplicate_uid','generation_during_fetch','reconnect_after_append','no_uidplus'],true))exit(2);$payload='';
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if($server===false)exit(2);
echo json_encode(['port'=>(int)substr(strrchr(stream_socket_get_name($server,false),':'),1)])."\n";flush();
$appends=0;$connections=0;$searches=0;$fetches=0;$deadline=hrtime(true)+180_000_000_000;
// The owning actor keeps stdin open across DB/S3/SMTP work; only EOF ends the idle peer.
while(hrtime(true)<$deadline){
    $read=[$server,STDIN];$write=[];$except=[];
    if(stream_select($read,$write,$except,1)===false)break;
    if(in_array(STDIN,$read,true)&&fread(STDIN,1)==='')break;
    if(!in_array($server,$read,true))continue;
    $client=@stream_socket_accept($server,1);if($client===false)continue;
    ++$connections;stream_set_timeout($client,3);fwrite($client,"* OK synthetic append fixture\r\n");
    while(hrtime(true)<$deadline&&($line=fgets($client))!==false){
        if(!preg_match('/^(\S+) (.*)\r\n$/D',$line,$m))break;$tag=$m[1];$command=strtoupper($m[2]);
        if(str_starts_with($command,'CAPABILITY'))fwrite($client,"* CAPABILITY IMAP4rev1 AUTH=PLAIN SASL-IR".($mode==='no_uidplus'?'':' UIDPLUS')." BINARY\r\n$tag OK capabilities\r\n");
        elseif(str_starts_with($command,'LOGIN')||str_starts_with($command,'AUTHENTICATE'))fwrite($client,"$tag OK authenticated\r\n");
        elseif(str_starts_with($command,'STATUS')&&preg_match('/^STATUS (.+) \(/i',$m[2],$box))fwrite($client,"* STATUS {$box[1]} (UIDVALIDITY 42)\r\n$tag OK status\r\n");
        elseif(str_starts_with($command,'APPEND')){
            ++$appends;if(!preg_match('/\{(\d+)\+?\}$/D',$m[2],$literal))break;$size=(int)$literal[1];if($size>1048576)break;
            if(!str_ends_with($m[2],'+}'))fwrite($client,"+ literal ready\r\n");$read=0;$payload='';while($read<$size){if(hrtime(true)>=$deadline)break 2;$chunk=fread($client,$size-$read);if($chunk===false||$chunk==='')break 2;$read+=strlen($chunk);$payload.=$chunk;}fgets($client);
            // Bytes accepted, response lost. No remote delivery exists. Another APPEND is counted as a replay.
            if($mode==='lost_ack')break;
            $proof=$mode==='missing_appenduid'?'':('[APPENDUID '.($mode==='wrong_appenduid'?'43':'42').' 1] ');fwrite($client,"$tag OK {$proof}synthetic stored\r\n");if($mode==='reconnect_after_append')break;
        }elseif(str_starts_with($command,'EXAMINE')){$generation=$mode==='selected_generation'?43:42;fwrite($client,"* 1 EXISTS\r\n* OK [UIDVALIDITY $generation] generation\r\n$tag OK [READ-ONLY] selected\r\n");}
        elseif(str_starts_with($command,'UID FETCH')){++$fetches;if($mode==='generation_during_fetch')fwrite($client,"* OK [UIDVALIDITY 43] changed\r\n");if($mode==='oversized_literal'){fwrite($client,"* 1 FETCH (UID 1 BODY[] {41943041}\r\n");break;}$prefix=in_array($mode,['uid_after_literal','duplicate_uid'],true)?'':'UID 1 ';$trailer=match($mode){'uid_after_literal'=>' UID 1','duplicate_uid'=>' UID 1 UID 2',default=>''};fwrite($client,"* 1 FETCH (".$prefix."BODY[] {".strlen($payload)."}\r\n".$payload.$trailer.")\r\n$tag OK fetched\r\n");}
        elseif(str_starts_with($command,'UID SEARCH')){++$searches;fwrite($client,"* SEARCH\r\n$tag OK search\r\n");}
        elseif(str_starts_with($command,'LOGOUT')){fwrite($client,"* BYE closed\r\n$tag OK logout\r\n");break;}
        else fwrite($client,"$tag BAD unsupported synthetic command\r\n");
    }
    fclose($client);if($connections>=4)break;
}
fclose($server);echo json_encode(['appends'=>$appends,'connections'=>$connections,'searches'=>$searches,'fetches'=>$fetches])."\n";
