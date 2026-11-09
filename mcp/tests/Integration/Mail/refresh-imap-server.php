<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// Labelled disposable loopback fixture. Never records authentication payloads.
$mode=$argv[1]??'';if(!in_array($mode,['empty','count','range','line','literal','epoch','unstable','no_modseq','copyuid','quoted_copyuid','space_esearch'],true))exit(2);
$s=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if($s===false)exit(2);echo json_encode(['port'=>(int)substr(strrchr(stream_socket_get_name($s,false),':'),1)])."\n";flush();$c=stream_socket_accept($s,5);if($c===false)exit(2);stream_set_timeout($c,3);fwrite($c,"* OK refresh fixture\r\n");$writes=0;$statuses=0;$commands=[];
while(($line=fgets($c))!==false){if(!preg_match('/^(\S+) (.*)\r\n$/D',$line,$m))break;$tag=$m[1];$cmd=strtoupper($m[2]);
 if(str_starts_with($cmd,'CAPABILITY'))fwrite($c,"* CAPABILITY IMAP4rev1 AUTH=PLAIN SASL-IR".($mode==='no_modseq'?'':' CONDSTORE')."\r\n$tag OK capabilities\r\n");
 elseif(str_starts_with($cmd,'LOGIN')||str_starts_with($cmd,'AUTHENTICATE'))fwrite($c,"$tag OK login\r\n");
 elseif(str_starts_with($cmd,'EXAMINE')){ $commands[]='EXAMINE';fwrite($c,"* FLAGS (\\Seen \\Flagged)\r\n* 0 EXISTS\r\n* OK [UIDVALIDITY 42] epoch\r\n* OK [UIDNEXT 2] next\r\n* OK [HIGHESTMODSEQ 7] modseq\r\n$tag OK [READ-ONLY] selected\r\n");}
 elseif(str_starts_with($cmd,'STATUS')){$statuses++;$generation=$mode==='epoch'&&$statuses>1?'43':'42';$h=$mode==='unstable'&&$statuses>1?'8':'7';$count=$mode==='count'?201:(in_array($mode,['range','line','literal'],true)?1:0);$commands[]='STATUS';if(in_array($mode,['copyuid','quoted_copyuid'],true))fwrite($c,($mode==='quoted_copyuid'?'* OK "[COPYUID" 42 1:1000 1:1000]':'* OK [COPYUID 42 1:1000 1:1000]')." unsolicited\r\n");fwrite($c,"* STATUS INBOX (UIDVALIDITY $generation UIDNEXT 2 MESSAGES $count HIGHESTMODSEQ $h)\r\n$tag OK status\r\n");}
 elseif(str_starts_with($cmd,'UID SEARCH')){$commands[]='UID SEARCH';if($mode==='space_esearch')fwrite($c,"*  ESEARCH UID ALL 1:1000\r\n");$ids=match($mode){'range'=>' 1:4294967295','line'=>' '.str_repeat('1 ',20000),'literal'=>' 1',default=>''};fwrite($c,"* SEARCH$ids\r\n$tag OK search\r\n");}
 elseif(str_starts_with($cmd,'UID FETCH')){$commands[]='UID FETCH';fwrite($c,"* 1 FETCH (UID 1 BODY[HEADER] {65537}\r\n");}
 elseif(str_starts_with($cmd,'LOGOUT')){fwrite($c,"* BYE bye\r\n$tag OK logout\r\n");break;}
 else{$writes++;fwrite($c,"$tag BAD unexpected\r\n");}
}
fclose($c);fclose($s);echo json_encode(['writes'=>$writes,'commands'=>$commands])."\n";
