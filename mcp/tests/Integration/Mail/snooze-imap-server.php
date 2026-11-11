<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// Only synthetic single-UID MOVE. Credentials and content are never logged.
$mode=$argv[1];$r=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if($server===false)exit(2);
echo json_encode(['port'=>(int)substr(strrchr(stream_socket_get_name($server,false),':'),1)])."\n";flush();$c=stream_socket_accept($server,8);$moves=0;$forbidden=0;$statuses=0;
if($c!==false){stream_set_timeout($c,3);fwrite($c,"* OK synthetic snooze\r\n");while(($line=fgets($c))!==false){if(!preg_match('/^(\S+) (.*)\r\n$/D',$line,$m))break;$tag=$m[1];$cmd=strtoupper($m[2]);
    if(str_starts_with($cmd,'CAPABILITY'))fwrite($c,"* CAPABILITY IMAP4rev1 AUTH=PLAIN SASL-IR UIDPLUS".($mode==='no_move'?'':' MOVE')."\r\n$tag OK capability\r\n");
    elseif(str_starts_with($cmd,'AUTHENTICATE')||str_starts_with($cmd,'LOGIN'))fwrite($c,"$tag OK authenticated\r\n");
    elseif(str_starts_with($cmd,'SELECT')||str_starts_with($cmd,'EXAMINE')){$dest=str_contains($cmd,strtoupper($r['destination_mailbox']));$g=$dest?$r['destination_generation']:$r['generation'];$access=$dest?'READ-ONLY':'READ-WRITE';fwrite($c,"* 1 EXISTS\r\n* OK [UIDVALIDITY $g] epoch\r\n$tag OK [$access] selected\r\n");}
    elseif(str_starts_with($cmd,'STATUS')){$statuses++;$g=(string)((int)$r['destination_generation']+($mode==='destination_generation_after'&&$statuses>1?1:0));fwrite($c,'* STATUS '.$r['destination_mailbox']." (UIDVALIDITY $g)\r\n$tag OK status\r\n");}
    elseif(str_starts_with($cmd,'UID FETCH')){if($mode==='source_generation')fwrite($c,'* OK [UIDVALIDITY '.((int)$r['generation']+1)."] changed\r\n");fwrite($c,'* 1 FETCH (UID '.$r['uid'].")\r\n$tag OK fetched\r\n");}
    elseif(str_starts_with($cmd,'UID MOVE')){$moves++;if($mode==='lost_ack')break;$src=$mode==='wrong_source'?(string)((int)$r['uid']+1):$r['uid'];$dst='999';if($mode==='multiple'){$src.=':4000000000';$dst.=':4000000000';}if($mode!=='missing_map')fwrite($c,'* OK [COPYUID '.$r['destination_generation'].' '.$src.' '.$dst."] mapped\r\n");fwrite($c,"* 1 EXPUNGE\r\n$tag OK moved\r\n");}
    elseif(str_starts_with($cmd,'LOGOUT')){fwrite($c,"* BYE done\r\n$tag OK logout\r\n");break;}
    else{$forbidden++;fwrite($c,"$tag BAD forbidden\r\n");}
}fclose($c);}fclose($server);echo json_encode(['moves'=>$moves,'forbidden'=>$forbidden])."\n";
