<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';use OCP\Server;
$stage='bootstrap';$checks=[];$exitCode=0;$global=null;$config=null;$changedWriter=false;
try {
 Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
 $db=Server::get(\OCP\IDBConnection::class);$users=Server::get(\OCP\IUserManager::class);$session=Server::get(\OCP\IUserSession::class);$config=Server::get(\OCP\IAppConfig::class);
 $global=$config->getValueBool('forms','allowComments',false);$config->setValueBool('forms','allowComments',true);
 $nonce=bin2hex(random_bytes(5));$actors=[];foreach(['owner','outsider'] as $role){$uid='recovery-http-'.$nonce.'-'.$role;$pw=bin2hex(random_bytes(24));$actors[$role]=['uid'=>$uid,'password'=>$pw,'user'=>$users->createUser($uid,$pw)];}
 $owner=$actors['owner'];$outsider=$actors['outsider'];$uid=$owner['uid'];$session->setUser($owner['user']);require '/tmp/origin-fixture.php';
 formsNativeOrigin($uid,'forms_capabilities');$session->setUser($outsider['user']);formsNativeOrigin($outsider['uid'],'forms_capabilities');$session->setUser($owner['user']);
 $rpc=static function(array $actor,string $name,array $args):array {
  $c=curl_init('http://127.0.0.1/index.php/apps/mcp/');curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_USERPWD=>$actor['uid'].':'.$actor['password'],CURLOPT_HTTPHEADER=>['Host: localhost','Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-06-18'],CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>$name==='__list'?'tools/list':'tools/call','params'=>$name==='__list'?(object)[]:['name'=>$name,'arguments'=>$args===[]?(object)[]:$args]],JSON_THROW_ON_ERROR)]);
  $body=curl_exec($c);$http=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);$r=json_decode((string)$body,true);if($http!==200||!is_array($r))throw new RuntimeException('Recovery HTTP transport failed.');if(isset($r['error']))return ['isError'=>true];return $r['result'];
 };
 $call=static function(string $tool,array $args,?array $actor=null)use($rpc,$owner):array {$r=$rpc($actor??$owner,$tool,$args);if($r['isError']??false)throw new RuntimeException('Refused '.$tool.': '.($r['content'][0]['text']??''));return $r['structuredContent']??json_decode($r['content'][0]['text'],true,64,JSON_THROW_ON_ERROR);};
 $deny=static function(string $tool,array $args,?array $actor=null)use($rpc,$owner):void {if(!($rpc($actor??$owner,$tool,$args)['isError']??false))throw new RuntimeException('Forbidden recovery reader admitted: '.$tool);};
 $write=static function(string $tool,array $args)use($call):array {$p=$call($tool,$args);return $call($tool,$args+['confirm'=>true,'confirm_shared'=>$p['requiresSharedConfirmation']??false,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]);};
 $drive=static function(string $original)use($write):array {for($i=0;$i<80;$i++){$r=$write('forms_continue_operation',['target_operation_id'=>$original]);if(($r['operation']['state']??null)==='succeeded'&&($r['data']['complete']??false))return $r;if(($r['operation']['state']??null)!=='pending')throw new RuntimeException('Unexpected bounded recovery state.');}throw new RuntimeException('Recovery fixture step budget exhausted.');};
 $stage='ready source preview';$api=Server::get(\OCA\Forms\Controller\ApiController::class);$id=$api->newForm()->getData()['id'];$caps=$call('forms_capabilities',[]);
 if(!$caps['guardedWrites']||!$caps['bulkLifecycle']||!$caps['bulkSourceWrites']||$caps['bulkSourceUnavailableReason']!==null)throw new RuntimeException('Qualified source readiness diagnostics missing.');$call('forms_delete_form',['id'=>$id]);$checks['qualified_source_available']=true;
 $stage='source writer migration unavailable';$changedWriter=$db->executeStatement('DELETE FROM *PREFIX*migrations WHERE app = ? AND version = ?',['forms','050402Date20261113070000'])===1;if(!$changedWriter)throw new RuntimeException('Expected exact comments writer migration.');
 try{
  $caps=$call('forms_capabilities',[]);if(!$caps['guardedWrites']||!$caps['bulkLifecycle']||$caps['bulkSourceWrites']||$caps['bulkSourceUnavailableReason']!=='qualified_comments_writer_required')throw new RuntimeException('Source writer loss incorrectly disabled protocol or failed diagnostics.');$checks['protocol_independent_source_diagnostics']=true;
  foreach(['forms_delete_form'=>['id'=>$id],'forms_delete_responses'=>['id'=>$id],'forms_transfer'=>['id'=>$id,'owner_id'=>$outsider['uid']]] as $tool=>$args)$deny($tool,$args);$checks['source_three_refused_without_fallback']=true;
  $listed=$rpc($owner,'__list',[]);$names=array_column($listed['tools']??[],'name');foreach(['forms_restore','forms_continue_operation','forms_cancel_operation'] as $tool)if(!in_array($tool,$names,true))throw new RuntimeException('Recovery/control disappeared from public tools/list.');foreach(['forms_delete_form','forms_delete_responses','forms_transfer'] as $tool)if(in_array($tool,$names,true))throw new RuntimeException('Unqualified source remained published.');$checks['recovery_control_publication_independent']=true;
 }finally{if($changedWriter){$db->executeStatement('INSERT INTO *PREFIX*migrations (app,version) VALUES (?,?)',['forms','050402Date20261113070000']);$changedWriter=false;}}
 $caps=$call('forms_capabilities',[]);if(!$caps['bulkSourceWrites'])throw new RuntimeException('Restored writer did not restore source readiness.');$call('forms_delete_form',['id'=>$id]);$checks['fresh_http_writer_restoration']=true;
 echo json_encode(['ok'=>true,'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$e->getMessage(),'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";$exitCode=1;}
finally{if($changedWriter)$db->executeStatement('INSERT INTO *PREFIX*migrations (app,version) VALUES (?,?)',['forms','050402Date20261113070000']);if($config!==null&&$global!==null)$config->setValueBool('forms','allowComments',$global);}
exit($exitCode);
