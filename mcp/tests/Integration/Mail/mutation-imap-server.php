<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// Scripted fixture server, never a product transport. Record command kinds only, never authentication payloads.
$mode=$argv[1]??'';if(!in_array($mode,['no','lost_ack','generation'],true))exit(2);
$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if($server===false)exit(2);
$address=stream_socket_get_name($server,false);echo json_encode(['port'=>(int)substr(strrchr($address,':'),1)])."\n";flush();
$c=stream_socket_accept($server,5);if($c===false)exit(2);stream_set_timeout($c,3);
fwrite($c,"* OK fixture\r\n");$stores=0;$applied=false;
while(($line=fgets($c))!==false){
    if(!preg_match('/^(\S+) (.*)\r\n$/D',$line,$match))break;$tag=$match[1];$command=strtoupper($match[2]);
    if(str_starts_with($command,'CAPABILITY'))fwrite($c,"* CAPABILITY IMAP4rev1 AUTH=PLAIN SASL-IR UIDPLUS\r\n$tag OK capability\r\n");
    elseif(str_starts_with($command,'AUTHENTICATE')||str_starts_with($command,'LOGIN'))fwrite($c,"$tag OK authenticated\r\n");
    elseif(str_starts_with($command,'SELECT'))fwrite($c,"* FLAGS (\\Seen \\Flagged)\r\n* 1 EXISTS\r\n* OK [UIDVALIDITY 42] generation\r\n* OK [PERMANENTFLAGS (\\Seen \\Flagged)] flags\r\n$tag OK [READ-WRITE] selected\r\n");
    elseif(str_starts_with($command,'UID FETCH')){
        if($mode==='generation')fwrite($c,"* OK [UIDVALIDITY 43] replaced\r\n");
        fwrite($c,"* 1 FETCH (UID 9 FLAGS ())\r\n$tag OK fetched\r\n");
    }elseif(str_starts_with($command,'UID STORE')){
        $stores++;if($mode==='lost_ack'){$applied=true;break;}
        fwrite($c,"$tag NO fixture refused store\r\n");
    }elseif(str_starts_with($command,'LOGOUT')){fwrite($c,"* BYE closed\r\n$tag OK logout\r\n");break;}
    else fwrite($c,"$tag BAD unsupported fixture command\r\n");
}
fclose($c);fclose($server);echo json_encode(['stores'=>$stores,'applied'=>$applied])."\n";
