<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
$mode=$argv[1];$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$server)exit(2);echo json_encode(['port'=>(int)substr(strrchr(stream_socket_get_name($server,false),':'),1)])."\n";flush();$c=stream_socket_accept($server,5);$creates=0;$forbidden=0;$exists=$mode==='preexisting';if($c){stream_set_timeout($c,3);fwrite($c,"* OK fixture\r\n");while(($line=fgets($c))!==false){if(!preg_match('/^(\S+) (.*)\r\n$/D',$line,$m))break;$tag=$m[1];$cmd=strtoupper($m[2]);
if(str_starts_with($cmd,'CAPABILITY'))fwrite($c,"* CAPABILITY IMAP4rev1 UIDPLUS\r\n$tag OK capability\r\n");
elseif(str_starts_with($cmd,'LOGIN')||str_starts_with($cmd,'AUTHENTICATE'))fwrite($c,"$tag OK authenticated\r\n");
elseif(str_starts_with($cmd,'LIST')||str_starts_with($cmd,'LSUB')){if($exists&&!str_contains($cmd,'DEST/'))fwrite($c,"* LIST () \"/\" \"Dest\"\r\n");fwrite($c,"$tag OK folders\r\n");}
elseif(str_starts_with($cmd,'CREATE')){$creates++;$exists=true;if($mode==='lost_ack')break;fwrite($c,$tag.($mode==='no'?" NO refused\r\n":" OK created\r\n"));}
elseif(str_starts_with($cmd,'STATUS'))fwrite($c,"* STATUS Dest (UIDVALIDITY 42)\r\n$tag OK status\r\n");
elseif(str_starts_with($cmd,'LOGOUT')){fwrite($c,"* BYE closed\r\n$tag OK logout\r\n");break;}
else{$forbidden++;fwrite($c,"$tag BAD forbidden\r\n");}}
fclose($c);}fclose($server);echo json_encode(['creates'=>$creates,'forbidden'=>$forbidden])."\n";
