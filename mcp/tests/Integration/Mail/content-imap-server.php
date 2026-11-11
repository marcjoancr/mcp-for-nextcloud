<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
$mode=$argv[1];$tls=str_starts_with($mode,'tls_');if($tls)$mode=substr($mode,4);$directory=getenv('MCP_IT_DELIVERY_DIRECTORY');$context=$tls?stream_context_create(['ssl'=>['local_cert'=>$directory.'/certificate.pem','local_pk'=>$directory.'/key.pem']]):stream_context_create();$server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$context);if($server===false)exit(2);
echo json_encode(['port'=>(int)substr(strrchr(stream_socket_get_name($server,false),':'),1)])."\n";flush();
$connections=0;$tls_connections=0;$writes=0;$fetches=0;$bodies=0;$body_sections=[];
while(($client=@stream_socket_accept($server,$connections===0?($mode==='service_quarantined'?2:20):(str_starts_with($mode,'service_http_')?10:1)))!==false){
 ++$connections;stream_set_timeout($client,3);if($tls){if(!@stream_socket_enable_crypto($client,true,STREAM_CRYPTO_METHOD_TLS_SERVER)){fclose($client);continue;}++$tls_connections;}fwrite($client,"* OK synthetic content fixture\r\n");
 while(($line=fgets($client))!==false){
  if(!preg_match('/^(\S+) (.*)\r\n$/D',$line,$m))break;$tag=$m[1];$command=strtoupper($m[2]);
  if(str_starts_with($command,'CAPABILITY'))fwrite($client,"* CAPABILITY IMAP4rev1 AUTH=PLAIN SASL-IR\r\n$tag OK capabilities\r\n");
  elseif(str_starts_with($command,'LOGIN')||str_starts_with($command,'AUTHENTICATE'))fwrite($client,"$tag OK authenticated\r\n");
  elseif(str_starts_with($command,'EXAMINE')){$g=$mode==='generation'?43:42;fwrite($client,"* 1 EXISTS\r\n* OK [UIDVALIDITY $g] generation\r\n$tag OK [READ-ONLY] selected\r\n");}
  elseif(str_starts_with($command,'UID FETCH')){
   ++$fetches;
   if(in_array($mode,['slow_line','slow_literal','service_http_slow_line'],true)){
    @fwrite($client,$mode!=='slow_literal'?"* OK ":"* 1 FETCH (UID 7 BODY[] {1024}\r\n");for($i=0;$i<220;$i++){if(@fwrite($client,'x')===false)break;usleep(100000);}break;
   }
   if(str_starts_with($mode,'service_related_')||str_starts_with($mode,'service_single_')){
    $related=str_starts_with($mode,'service_related_');$start=str_contains($mode,'_start_');$html=$related||str_contains($mode,'_html_');$primary=$start?'2':'1';$resource=$start?'1':'2';$media=$html?'text/html':'text/plain';$body=$html?'<p>primary</p>':'primary';
    $leaf='("TEXT" "'.($html?'HTML':'PLAIN').'" ("CHARSET" "UTF-8") "<primary@example.test>" NIL "7BIT" '.strlen($body).' 1 NIL ("INLINE" NIL) NIL)';
    $resourceLeaf='("TEXT" "PLAIN" ("CHARSET" "UTF-8") "<resource@example.test>" NIL "7BIT" 2097152 1 NIL ("INLINE" NIL) NIL)';
    $params='("BOUNDARY" "b" "TYPE" "text/html"'.($start?' "START" "<primary@example.test>"':'').')';$tree=$related?'('.($start?$resourceLeaf.$leaf:$leaf.$resourceLeaf).' "RELATED" '.$params.' NIL NIL)':$leaf;
    $type=$related?'multipart/related; boundary=b; type="text/html"'.($start?'; start="<primary@example.test>"':''):$media.'; charset=utf-8';
    if(str_contains($mode,'_start_lie_'))$type=str_replace('<primary@example.test>','<resource@example.test>',$type);if(str_contains($mode,'_type_lie_'))$type=str_replace('type="text/html"','type="text/plain"',$type);
    $root="From: sender@example.test\r\nTo: recipient@example.test\r\nSubject: Fixture root\r\nContent-Type: ".$type."\r\n".($related?'':"Content-ID: <primary@example.test>\r\nContent-Disposition: inline\r\n")."\r\n";
    if(str_contains($command,'FLAGS')){@fwrite($client,"* 1 FETCH (UID 7 FLAGS (\\Draft))\r\n$tag OK flags\r\n");continue;}
    if(str_contains($command,'BODYSTRUCTURE')){@fwrite($client,"* 1 FETCH (UID 7 BODYSTRUCTURE $tree)\r\n$tag OK structure\r\n");continue;}
    if(!preg_match('/BODY\.PEEK\[([^]]*)\]/',$command,$section)){++$writes;break;}$section=$section[1];
    if($section==='')$payload=$root.$body;
    elseif($section==='HEADER')$payload=$root;
    elseif($section===$primary.'.MIME'&&$related)$payload="Content-Type: $media; charset=utf-8\r\nContent-ID: <primary@example.test>\r\nContent-Disposition: inline\r\n\r\n";
    elseif($section===$resource.'.MIME'&&$related)$payload="Content-Type: text/plain; charset=utf-8\r\nContent-ID: <resource@example.test>\r\nContent-Disposition: inline\r\n\r\n";
    elseif($section===$primary)$payload=$body;
    elseif($section===$resource&&$related)$payload=str_repeat('r',2097152);
    else{++$writes;@fwrite($client,"$tag BAD unexpected section\r\n");continue;}
    if($section===''||ctype_digit($section)){++$bodies;$body_sections[]=$section;}
    @fwrite($client,"* 1 FETCH (UID 7 BODY[$section] {".strlen($payload)."}\r\n");$offset=0;while($offset<strlen($payload)){$n=@fwrite($client,substr($payload,$offset,8192));if($n===false||$n===0)break 2;$offset+=$n;}@fwrite($client,")\r\n$tag OK fetched\r\n");continue;
   }
   if(str_starts_with($mode,'service_')&&!in_array($mode,['service_raw','service_http_raw','service_changed','service_quarantined'],true)){
    if(str_contains($command,'FLAGS')){fwrite($client,"* 1 FETCH (UID 7 FLAGS (".($mode==='service_not_draft'?'':'\\Draft')."))\r\n$tag OK flags\r\n");continue;}
    if(str_contains($command,'BODYSTRUCTURE')){$tree='(("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 4 1 NIL NIL NIL)("APPLICATION" "OCTET-STREAM" ("NAME" "big.bin") NIL NIL "BASE64" 1 NIL ("ATTACHMENT" ("FILENAME" "big.bin")) NIL) "MIXED" ("BOUNDARY" "b") NIL NIL)';fwrite($client,"* 1 FETCH (UID 7 BODYSTRUCTURE $tree)\r\n$tag OK metadata\r\n");continue;}
    if(!preg_match('/BODY\.PEEK\[([^]]*)\]/',$command,$section)){++$writes;break;}$section=$section[1];
    $root="From: sender@example.test\r\nTo: recipient@example.test\r\nBcc: hidden@example.test\r\nIn-Reply-To: <parent@example.test>\r\nSubject: Synthetic draft\r\nContent-Type: ".(in_array($mode,['service_crypto_lie','service_copy_crypto'],true)?'application/pkcs7-mime':'multipart/mixed; boundary=b')."\r\n\r\n";
    if(in_array($mode,['service_copy','service_copy_lost','service_http_draft_index','service_copy_unsupported'],true))$root="References: <parent@example.test>\r\n".$root;
    if($mode==='service_copy_unsupported')$root=str_replace('References: <parent@example.test>','References: <earlier@example.test> <parent@example.test>',$root);
    if($mode==='service_draft_revision')$root="X-Revision: $connections\r\n".$root;
    $payload=match($section){'HEADER'=>$root,'1.MIME'=>"Content-Type: text/plain; charset=utf-8\r\n\r\n",'2.MIME'=>"Content-Type: application/octet-stream; name=big.bin\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=big.bin\r\n\r\n",'1'=>match($mode){'service_body_over'=>str_repeat('x',1048577),'service_index_store_large'=>str_repeat('x',20001),'service_index_store_lexeme'=>str_repeat('x',2047),default=>'text'},'2'=>base64_encode(str_repeat('a',in_array($mode,['service_part_large','service_part_over','service_http_part'],true)?26214400+($mode==='service_part_over'?1:0):3)),default=>''};
    if(in_array($section,['1','2'],true)){++$bodies;$body_sections[]=$section;}
    @fwrite($client,"* 1 FETCH (UID 7 BODY[$section] {".strlen($payload)."}\r\n");$offset=0;while($offset<strlen($payload)){$n=@fwrite($client,substr($payload,$offset,8192));if($n===false||$n===0)break 2;$offset+=$n;}@fwrite($client,")\r\n$tag OK fetched\r\n");continue;
   }
   if(str_contains($command,'BODYSTRUCTURE')){$leaf='("TEXT" "PLAIN" NIL NIL NIL "7BIT" 35900000 1)';$tree=match($mode){'deep_tree'=>str_repeat('(',30).$leaf.str_repeat(' "MIXED")',30),'many_parts'=>'('.str_repeat($leaf,101).' "MIXED")','many_params'=>'("TEXT" "PLAIN" ('.str_repeat('"X" "Y" ',33).') NIL NIL "7BIT" 3 1)',default=>$leaf};@fwrite($client,"* 1 FETCH (UID 7 BODYSTRUCTURE $tree)\r\n$tag OK fetched\r\n");continue;}
   ++$bodies;
   if($mode==='huge_literal'){fwrite($client,"* 1 FETCH (UID 7 BODY[] {26214401}\r\n");break;}
   if($mode==='huge_line'){@fwrite($client,'* OK '.str_repeat('x',32769)."\r\n");break;}
   if($mode==='timeout'){sleep(3);break;}
   if($mode==='generation_during')fwrite($client,"* OK [UIDVALIDITY 43] changed\r\n");
   if($mode==='unsolicited_structure'){fwrite($client,"* 1 FETCH (UID 7 BODYSTRUCTURE (\"TEXT\" \"PLAIN\" NIL NIL NIL \"7BIT\" 3 1))\r\n");break;}
   $payload="Content-Type: application/pkcs7-mime\r\n\r\nOpaque synthetic bytes\0\xff";
   $before=in_array($mode,['uid_after','wrong_uid','duplicate_uid'],true)?'':'UID 7 ';$after=match($mode){'uid_after'=>' UID 7','wrong_uid'=>' UID 8','duplicate_uid'=>' UID 7 UID 7',default=>''};
   @fwrite($client,"* 1 FETCH (".$before.'BODY[] {'.strlen($payload)."}\r\n".$payload.$after.")\r\n");if($mode==='missing_tag')break;@fwrite($client,$tag.($mode==='negative_tag'?' NO refused':' OK fetched')."\r\n");
  }elseif(str_starts_with($command,'LOGOUT')){@fwrite($client,"* BYE closed\r\n$tag OK logout\r\n");break;}
  else{++$writes;@fwrite($client,"$tag BAD unsupported command\r\n");}
 }
 fclose($client);if($connections>=8)break;
}
fclose($server);echo json_encode(compact('connections','tls_connections','writes','fetches','bodies','body_sections'))."\n";
