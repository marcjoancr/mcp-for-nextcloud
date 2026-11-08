<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// Scripted protocol peer only. The product uses Mail's existing client, never this fixture transport.
$mode=$argv[1]??'';$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if($server===false)exit(2);
echo json_encode(['port'=>(int)substr(strrchr(stream_socket_get_name($server,false),':'),1)])."\n";flush();
$c=stream_socket_accept($server,5);if($c===false){echo json_encode(['moves'=>0,'forbidden'=>0])."\n";exit(0);}stream_set_timeout($c,3);fwrite($c,"* OK fixture\r\n");$moves=0;$forbidden=0;$statuses=0;
while(($line=fgets($c))!==false){
    if(!preg_match('/^(\S+) (.*)\r\n$/D',$line,$m))break;$tag=$m[1];$cmd=strtoupper($m[2]);
    if(str_starts_with($cmd,'CAPABILITY')){
        $caps='IMAP4rev1 AUTH=PLAIN SASL-IR';if($mode!=='no_move')$caps.=' MOVE';if($mode!=='no_uidplus')$caps.=' UIDPLUS';fwrite($c,"* CAPABILITY $caps\r\n$tag OK capability\r\n");
    }elseif(str_starts_with($cmd,'AUTHENTICATE')||str_starts_with($cmd,'LOGIN'))fwrite($c,"$tag OK authenticated\r\n");
    elseif(str_starts_with($cmd,'SELECT')||str_starts_with($cmd,'EXAMINE')){
        $dest=str_contains($cmd,'DEST');$gen=$dest?'43':'42';$modeReply=$dest||$mode==='read_only_source'?'READ-ONLY':'READ-WRITE';
        fwrite($c,"* 1 EXISTS\r\n* OK [UIDVALIDITY $gen] generation\r\n");if(($mode==='nonsticky'&&!$dest)||($mode==='destination_nonsticky'&&$dest))fwrite($c,"* OK [UIDNOTSTICKY] temporary ids\r\n");fwrite($c,"$tag OK [$modeReply] selected\r\n");
    }elseif(str_starts_with($cmd,'STATUS')){
        $statuses++;
        if($mode==='destination_missing')fwrite($c,"$tag NO [NONEXISTENT] gone\r\n");
        else {$gen=$mode==='destination_generation_before'||($mode==='destination_generation_after'&&$statuses>1)?'44':'43';fwrite($c,"* STATUS Dest (UIDVALIDITY $gen)\r\n$tag OK status\r\n");}
    }elseif(str_starts_with($cmd,'UID FETCH')){
        if($mode==='unexpected_copyuid')fwrite($c,"* OK [COPYUID 43 9 17] unsolicited\r\n");
        if($mode==='source_generation')fwrite($c,"* OK [UIDVALIDITY 44] replaced\r\n");
        if($mode!=='source_gone')fwrite($c,"* 1 FETCH (UID 9)\r\n");fwrite($c,"$tag OK fetched\r\n");
    }elseif(str_starts_with($cmd,'UID MOVE')){
        $moves++;if($mode==='lost_ack')break;
        if($mode==='no'){fwrite($c,"$tag NO refused\r\n");continue;}
        $mapping=match($mode){'wrong_source'=>'43 10 17','wrong_destination'=>'44 9 17','multiple'=>'43 9:999999999 17:999999999','malformed'=>'43 9','zero'=>'43 9 0',default=>'43 9 17'};
        if($mode!=='missing_map')fwrite($c,"* OK [COPYUID $mapping] mapped\r\n");
        if($mode==='duplicate_map')fwrite($c,"* OK [COPYUID 43 9 17] repeated\r\n");
        fwrite($c,"* 1 EXPUNGE\r\n$tag OK moved\r\n");
    }elseif(str_starts_with($cmd,'LOGOUT')){fwrite($c,"* BYE closed\r\n$tag OK logout\r\n");break;}
    else {$forbidden++;fwrite($c,"$tag BAD forbidden fixture command\r\n");}
}
fclose($c);fclose($server);echo json_encode(['moves'=>$moves,'forbidden'=>$forbidden])."\n";
