<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')exit(2);
define('OC_CONSOLE',true);chdir('/var/www/html');require 'lib/base.php';use OCP\Server;
$stage='bootstrap';$checks=[];$exitCode=0;$global=null;$config=null;
try {
 Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
 $db=Server::get(\OCP\IDBConnection::class);$users=Server::get(\OCP\IUserManager::class);$session=Server::get(\OCP\IUserSession::class);$config=Server::get(\OCP\IAppConfig::class);
 $global=$config->getValueBool('forms','allowComments',false);$config->setValueBool('forms','allowComments',true);
 $nonce=bin2hex(random_bytes(5));$actors=[];foreach(['owner','outsider'] as $role){$uid='recovery-http-'.$nonce.'-'.$role;$pw=bin2hex(random_bytes(24));$actors[$role]=['uid'=>$uid,'password'=>$pw,'user'=>$users->createUser($uid,$pw)];}
 $owner=$actors['owner'];$outsider=$actors['outsider'];$uid=$owner['uid'];$session->setUser($owner['user']);require '/tmp/origin-fixture.php';
 formsNativeOrigin($uid,'forms_capabilities');$session->setUser($outsider['user']);formsNativeOrigin($outsider['uid'],'forms_capabilities');$session->setUser($owner['user']);
 $rpc=static function(array $actor,string $name,array $args):array {
  $c=curl_init('http://127.0.0.1/index.php/apps/mcp/');curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_USERPWD=>$actor['uid'].':'.$actor['password'],CURLOPT_HTTPHEADER=>['Host: localhost','Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-06-18'],CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],JSON_THROW_ON_ERROR)]);
  $body=curl_exec($c);$http=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);$r=json_decode((string)$body,true);if($http!==200||!is_array($r))throw new RuntimeException('Recovery HTTP transport failed.');if(isset($r['error']))return ['isError'=>true];return $r['result'];
 };
 $call=static function(string $tool,array $args,?array $actor=null)use($rpc,$owner):array {$r=$rpc($actor??$owner,$tool,$args);if($r['isError']??false)throw new RuntimeException('Refused '.$tool.': '.($r['content'][0]['text']??''));return $r['structuredContent']??json_decode($r['content'][0]['text'],true,64,JSON_THROW_ON_ERROR);};
 $deny=static function(string $tool,array $args,?array $actor=null)use($rpc,$owner):void {if(!($rpc($actor??$owner,$tool,$args)['isError']??false))throw new RuntimeException('Forbidden recovery reader admitted: '.$tool);};
 $write=static function(string $tool,array $args)use($call):array {$p=$call($tool,$args);return $call($tool,$args+['confirm'=>true,'confirm_shared'=>$p['requiresSharedConfirmation']??false,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]);};
 $drive=static function(string $original)use($write):array {for($i=0;$i<80;$i++){$r=$write('forms_continue_operation',['target_operation_id'=>$original]);if(($r['operation']['state']??null)==='succeeded'&&($r['data']['complete']??false))return $r;if(($r['operation']['state']??null)!=='pending')throw new RuntimeException('Unexpected bounded recovery state.');}throw new RuntimeException('Recovery fixture step budget exhausted.');};
 $stage='folder-only managed share';$id=Server::get(\OCA\Forms\Controller\ApiController::class)->newForm()->getData()['id'];
 $form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);$home=Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);$folder=$home->newFolder(Server::get(\OCA\Forms\Helper\FilePathHelper::class)->getFormUploadedFilesFolderPath($form));
 $native=new \OCA\Forms\Db\Share();$native->setFormId($id);$native->setShareType(0);$native->setShareWith($outsider['uid']);$native->setPermissions(['submit','results']);$native=Server::get(\OCA\Forms\Db\ShareMapper::class)->insert($native);
 $manager=Server::get(\OCP\Share\IManager::class);$share=$manager->newShare();$share->setNode($folder);$share->setShareType(0);$share->setSharedWith($outsider['uid']);$share->setSharedBy($uid);$share->setPermissions(1);$share=$manager->createShare($share);
 $db->executeStatement('INSERT INTO *PREFIX*forms_mcp_file_shares (form_id,native_share_id,folder_id,files_share_id) VALUES (?,?,?,?)',[$id,$native->getId(),$folder->getId(),$share->getFullId()]);
 $p=$call('forms_delete_form',['id'=>$id]);if(($p['impact']['bulk']['counts']['files']??null)!==0||($p['impact']['bulk']['counts']['managed_shares']??null)!==1)throw new RuntimeException('Exact folder-only case missing.');
 $args=['cursor'=>$p['impact']['bulk']['collections']['managed_shares']['cursor'],'limit'=>100];$before=Server::get(\OCA\Forms\Mcp\Service\FormsRevisionFence::class)->revision();$page=$call('forms_read_operation_impact',$args);
 if($page['collection']!=='managed_shares'||count($page['items'])!==1||$page['hasMore']||$page['nextCursor']!==null||!in_array((int)$folder->getId(),$page['items'][0]['folder_ids'],true))throw new RuntimeException('Managed folder share projection is incomplete.');$checks['folder_only_public_control']=true;
 $policy=Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);$policy->setGrant($uid,'files','share',false);try{$deny('forms_read_operation_impact',$args);}finally{$policy->setGrant($uid,'files','share',true);}$checks['original_dynamic_files_share_grant']=true;
 $writer=Server::get(\OCA\Mcp\Access\ResourceScope\ResourceScopeWriter::class);$state=Server::get(\OCA\Mcp\Db\PolicyStateMapper::class);$old=Server::get(\OCA\Mcp\Db\ResourceScopeMapper::class)->forModule($uid,'files');$writer->setResourceScope($state->state()['revision'],$uid,$uid,'files',[]);
 try{$deny('forms_read_operation_impact',$args);}finally{$writer->setResourceScope($state->state()['revision'],$uid,$uid,'files',$old===null?null:$old['items']);}$checks['current_files_scope_excludes_folder']=true;
 $selections=Server::get(\OCA\Mcp\OAuth\ConnectionToolsStore::class);foreach(['forms_read_operation_impact','forms_delete_form'] as $disabled){$old=$selections->get($uid,0);$selections->save($uid,0,array_values(array_diff($old['tools'],[$disabled])),$old['revision'],$old['variants']??[]);try{$deny('forms_read_operation_impact',$args);}finally{$current=$selections->get($uid,0);$selections->save($uid,0,$old['tools'],$current['revision'],$old['variants']??[]);}}$checks['current_and_original_tool_selection']=true;
 $page=$call('forms_read_operation_impact',$args);if(count($page['items'])!==1||$before!==Server::get(\OCA\Forms\Mcp\Service\FormsRevisionFence::class)->revision()||$manager->getShareById($share->getFullId())->getPermissions()!==1)throw new RuntimeException('Scope checks changed native sharing or failed recovery of admission.');$checks['sharing_read_only_after_denials']=true;
 echo json_encode(['ok'=>true,'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){echo json_encode(['ok'=>false,'stage'=>$stage,'error'=>$e->getMessage(),'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";$exitCode=1;}
finally{if($config!==null&&$global!==null)$config->setValueBool('forms','allowComments',$global);}
exit($exitCode);
