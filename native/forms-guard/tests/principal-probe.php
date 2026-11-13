<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Forms\Mcp\Guard;
use OCA\Forms\Mcp\Service\ScopedServices;
function changePrincipal(array $request): void {
 $process = proc_open([PHP_BINARY, __DIR__.'/principal-writer.php'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
 fwrite($pipes[0], json_encode($request)); fclose($pipes[0]);
 $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
 if (proc_close($process)!==0 || trim($output)!=='committed') {throw new RuntimeException('Fixture mutation failed: '.$error);}
}
try {
 Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
 $request = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
 $case = $request['case']; $uid='principal-'.bin2hex(random_bytes(5));
 $user=Server::get(\OCP\IUserManager::class)->createUser($uid,bin2hex(random_bytes(24)));
 Server::get(\OCP\IUserSession::class)->setUser($user);
 $container=(new \OCA\Forms\AppInfo\Application())->getContainer(); $guard=$container->get(Guard::class);
 $api=$container->get(\OCA\Forms\Controller\ApiController::class); $id=$api->newForm()->getData()['id'];
 $groups=Server::get(\OCP\IGroupManager::class); $apps=Server::get(\OCP\App\IAppManager::class);
 $user->isEnabled();$groups->getUserGroupIds($user);$apps->isEnabledForUser('forms',$user);$apps->isEnabledForUser('mcp',$user);
 $guard->read($uid,'forms_read_settings',['id'=>$id]);
 if (in_array($case,['enabled','forms','mcp','admin'],true)) {
  if ($case==='admin') {$groups->get('admin')->addUser($user);$guard->read($uid,'forms_read_admin_settings',[]);}
  $action=$case==='enabled'?'enabled':($case==='admin'?'membership':'app');
  $change=['uid'=>$uid,'action'=>$action,'value'=>false];$restore=$change+[];$restore['value']=true;
  if ($action==='app') {$change['app']=$restore['app']=$case;$change['value']='no';$restore['value']='yes';}
  if ($action==='membership') {$change['group']=$restore['group']='admin';}
  changePrincipal($change);
  try {
   $refused=false;
   try {$guard->read($uid,$case==='admin'?'forms_read_admin_settings':'forms_read_settings',$case==='admin'?[]:['id'=>$id]);}
   catch (RuntimeException $error) {$refused=true;}
   $warm=$case==='enabled'?$user->isEnabled():($case==='admin'?$groups->isAdmin($uid):$apps->isEnabledForUser($case,$user));
   $result=['refused'=>$refused,'original_cache_unchanged'=>$warm];
  } finally {changePrincipal($restore);}
 } elseif ($case==='config') {
  $values=['allowPermitAll'=>true,'allowPublicLink'=>true,'allowCustomPublicShareTokens'=>false,'allowShowToAll'=>true,'creationAllowedGroups'=>[],'restrictCreation'=>false,'allowConfirmationEmail'=>false,'confirmationEmailRateLimit'=>3,'allowComments'=>false];
  $config=Server::get(\OCP\IAppConfig::class);$original=[];
  foreach ($values as $key=>$value) {$original[$key]=$config->hasKey('forms',$key)?$config->getAllValues('forms')[$key]:null;}
  $old=(new ScopedServices($uid))->get(\OCA\Forms\Service\ConfigService::class)->getAppConfig();
  changePrincipal(['uid'=>$uid,'action'=>'config','values'=>array_fill_keys(array_keys($values),null)]);
  try {
   $defaults=(new ScopedServices($uid))->get(\OCA\Forms\Service\ConfigService::class)->getAppConfig();
   changePrincipal(['uid'=>$uid,'action'=>'config','values'=>['restrictCreation'=>true,'creationAllowedGroups'=>[],'allowPublicLink'=>false]]);
   $fresh=(new ScopedServices($uid))->get(\OCA\Forms\Service\ConfigService::class)->getAppConfig();
   $result=['defaults'=>array_intersect_key($defaults,$values),'restricted'=>!$fresh['canCreateForms']&&!$fresh['allowPublicLink']];
  } finally {changePrincipal(['uid'=>$uid,'action'=>'config','values'=>$original]);}
 } elseif ($case==='validator') {
  $config=Server::get(\OCP\IAppConfig::class);$key='allowCustomPublicShareTokens';$original=$config->getValueBool('forms',$key,false);$config->setValueBool('forms',$key,true);
  $controller=$container->get(\OCA\Forms\Controller\ShareApiController::class);$controller->generateToken();$share=$controller->newShare($id,3,'',['submit'])->getData();
  $mapper=Server::get(\OCA\Forms\Db\ShareMapper::class);$token=$mapper->findById($share['id'])->getShareWith();
  require __DIR__.'/origin-fixture.php';$input=['id'=>$id,'share_id'=>$share['id'],'token'=>'fresh-principal-'.bin2hex(random_bytes(8))];$input['_mcp_origin']=formsNativeOrigin($uid,'forms_update_share',$input);$snapshot=$guard->snapshot($uid,'forms_update_share',$input);
  changePrincipal(['uid'=>$uid,'action'=>'config','values'=>[$key=>false]]);
  try {
   $refused=false;try{$guard->apply($uid,'forms_update_share',$input,$snapshot['revision'],bin2hex(random_bytes(16)),$snapshot['file_manifest']);}catch(\OCP\AppFramework\OCS\OCSForbiddenException){$refused=true;}
   $result=['refused'=>$refused,'token_preserved'=>$mapper->findById($share['id'])->getShareWith()===$token];
  } finally {changePrincipal(['uid'=>$uid,'action'=>'config','values'=>[$key=>$original]]);}
 } elseif ($case==='group') {
  $gid='principal-group-'.bin2hex(random_bytes(4));$group=$groups->createGroup($gid);$group->addUser($user);
  $owner=Server::get(\OCP\IUserManager::class)->get('guard-owner');
  Server::get(\OCP\IUserSession::class)->setUser($owner);
  $ownerScope=new ScopedServices('guard-owner');
  $shared=$ownerScope->get(\OCA\Forms\Controller\ApiController::class)->newForm()->getData()['id'];
  $ownerScope->get(\OCA\Forms\Controller\ShareApiController::class)->newShare($shared,1,$gid,['submit','results']);
  Server::get(\OCP\IUserSession::class)->setUser($user);
  $groups->getUserGroupIds($user);
  $entity=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($shared);
  $before=(new ScopedServices($uid))->forms()->getPermissions($entity);
  changePrincipal(['uid'=>$uid,'action'=>'membership','group'=>$gid,'value'=>false]);
  $after=(new ScopedServices($uid))->forms()->getPermissions($entity);
  $result=['had_results'=>in_array('results',$before,true),'revoked'=>!in_array('results',$after,true),'global_still_warm'=>in_array($gid,$groups->getUserGroupIds($user),true)];
 } elseif ($case==='app_group') {
  $gid='app-group-'.bin2hex(random_bytes(4));$group=$groups->createGroup($gid);$group->addUser($user);$groups->getUserGroupIds($user);
  changePrincipal(['uid'=>$uid,'action'=>'app','app'=>'forms','value'=>json_encode([$gid])]);
  try {
   $guard->assertPrincipal($uid);
   changePrincipal(['uid'=>$uid,'action'=>'membership','group'=>$gid,'value'=>false]);
   $refused=false;try{$guard->assertPrincipal($uid);}catch(RuntimeException){$refused=true;}
   $result=['refused'=>$refused];
  } finally {changePrincipal(['uid'=>$uid,'action'=>'app','app'=>'forms','value'=>'yes']);}
 } elseif ($case==='admin_granted') {
  $groups->isAdmin($uid);
  changePrincipal(['uid'=>$uid,'action'=>'membership','group'=>'admin','value'=>true]);
  $guard->assertPrincipal($uid,true);
  $result=['original_cache_unchanged'=>!$groups->isAdmin($uid)];
 } elseif ($case==='unknown_group_backend') {
  $groups->addBackend(new class extends \OC\Group\Database {});
  $refused=false;try{$guard->assertPrincipal($uid);}catch(RuntimeException $error){$refused=$error->getMessage()==='Forms group backend is unqualified';}
  $result=['refused'=>$refused];
 } elseif ($case==='unknown_backend') {
  $unknown=new class extends \OC\User\Database {};
  (new ReflectionProperty($user,'backend'))->setValue($user,$unknown);
  $refused=false;try{$guard->assertPrincipal($uid);}catch(RuntimeException $error){$refused=$error->getMessage()==='Forms user backend is unqualified';}
  $result=['refused'=>$refused];
 } elseif ($case==='replica') {
  $config=Server::get(\OCP\IConfig::class);$original=$config->getSystemValue('dbreplica',null);
  $params=Server::get(\OCP\IDBConnection::class)->getInner()->getParams();
  try {
   $config->setSystemValue('dbreplica',[$params['primary']]);
   $refused=false;try{$guard->assertPrincipal($uid);}catch(RuntimeException){$refused=true;}
   $result=['qualified'=>$guard->capabilities()['qualified'],'refused'=>$refused];
  } finally {if($original===null){$config->deleteSystemValue('dbreplica');}else{$config->setSystemValue('dbreplica',$original);}}
 } elseif ($case==='session') {
  Server::get(\OCP\IUserSession::class)->setUser(null);$guard->assertPrincipal($uid);
  $result=['session'=>Server::get(\OCP\IUserSession::class)->getUser()?->getUID()];
 } else {throw new RuntimeException('Unknown principal test case');}
 echo json_encode(['ok'=>true,'result'=>$result]);
} catch(Throwable $error) {echo json_encode(['ok'=>false,'error'=>$error->getMessage(),'class'=>$error::class]);}
