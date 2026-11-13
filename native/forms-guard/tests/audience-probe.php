<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';
use OCP\Server;
use OCA\Forms\Mcp\Service\{ScopedServices,EffectEnvelope,OriginContext};
function addAudienceMember(string $uid,string $group):void {
 $p=proc_open([PHP_BINARY,__DIR__.'/principal-writer.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode(['uid'=>$uid,'action'=>'membership','group'=>$group,'value'=>true]));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0||trim($out)!=='committed')throw new RuntimeException($err);
}
try {
 $request=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$uid=$request['uid']??'guard-owner';Server::get(\OCP\App\IAppManager::class)->loadApp('forms');Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));
 $container=(new \OCA\Forms\AppInfo\Application())->getContainer();$api=$container->get(\OCA\Forms\Controller\ApiController::class);$shares=$container->get(\OCA\Forms\Controller\ShareApiController::class);$guard=$container->get(\OCA\Forms\Mcp\Guard::class);require __DIR__.'/origin-fixture.php';
 if($request['case']==='audience'){
  $id=$api->newForm()->getData()['id'];$groups=Server::get(\OCP\IGroupManager::class);$gid='audience-'.bin2hex(random_bytes(5));$group=$groups->createGroup($gid);$group->addUser(Server::get(\OCP\IUserManager::class)->get($uid));$next='audience-next-'.bin2hex(random_bytes(5));Server::get(\OCP\IUserManager::class)->createUser($next,bin2hex(random_bytes(24)));$shares->newShare($id,1,$gid,['submit','results']);
  $group->getUsers();$origin=formsNativeOrigin($uid,'forms_submit_response',['id'=>$id]);$scope=new ScopedServices($uid,true);
  $before=OriginContext::within($uid,$origin,'forms_submit_response',['id'=>$id],fn()=>EffectEnvelope::digest($id,'share',0,$scope));
  addAudienceMember($next,$gid);
  $after=OriginContext::within($uid,$origin,'forms_submit_response',['id'=>$id],fn()=>EffectEnvelope::digest($id,'share',0,new ScopedServices($uid,true)));
  $frozen=$scope->get(\OCP\IGroupManager::class)->get($gid)->getUsers();$fresh=(new ScopedServices($uid,true))->get(\OCP\IGroupManager::class)->get($gid)->getUsers();
  $result=['digest_changed'=>$before!==$after,'frozen_count'=>count($frozen),'fresh_count'=>count($fresh),'global_count'=>count($group->getUsers()),'same_delivery_graph'=>(new ReflectionProperty($scope->forms(),'groupManager'))->getValue($scope->forms())===$scope->get(\OCP\IGroupManager::class)];
 }elseif($request['case']==='deferred_metadata'){
  $id=$api->newForm()->getData()['id'];$origin=formsNativeOrigin($uid,'forms_download_results',['id'=>$id]);Server::get(\OCP\IUserSession::class)->setUser(null);$result=$guard->deferredRead($origin,'forms_results_metadata',['id'=>$id]);$result=['same_form'=>$result['form']['id']===$id,'session'=>Server::get(\OCP\IUserSession::class)->getUser()?->getUID()];
 }elseif($request['case']==='circle_fixture'){
  Server::get(\OCP\App\IAppManager::class)->loadApp('circles');$id=$api->newForm()->getData()['id'];$q=$api->newQuestion($id,'short',null,'Circle answer')->getData()['id'];$api->newSubmission($id,[$q=>['Circle response']]);$sid=Server::get(\OCA\Forms\Db\SubmissionMapper::class)->findByForm($id)[0]->getId();$recipient='circle-reader-'.bin2hex(random_bytes(5));$user=Server::get(\OCP\IUserManager::class)->createUser($recipient,bin2hex(random_bytes(24)));
  Server::get(\OCP\IUserSession::class)->setUser($user);$circles=Server::get(\OCA\Circles\CirclesManager::class);$circles->startSession($circles->getLocalFederatedUser($recipient),true);$circle=$circles->createCircle('Forms reader '.bin2hex(random_bytes(5)),null,false,true,false);$circles->stopSession();
  Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($uid));$shares->newShare($id,7,$circle->getSingleId(),['submit','results','edit']);$api->updateForm($id,['lockedUntil'=>null]);$result=['id'=>$id,'uid'=>$recipient,'submission_id'=>$sid];
 }elseif($request['case']==='circle_read'){
  Server::get(\OCP\App\IAppManager::class)->loadApp('circles');$id=$request['id'];$native=$container->get(\OCA\Forms\Service\FormsService::class);$form=Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id);$baseline=$native->getPermissions($form);$read=$guard->read($uid,'forms_read_settings',['id'=>$id]);$response=$guard->read($uid,'forms_read_response',['id'=>$id,'submission_id'=>$request['submission_id']]);$input=['id'=>$id,'fields'=>['description'=>'Circle editor']];$input['_mcp_origin']=formsNativeOrigin($uid,'forms_update',$input);$snapshot=$guard->snapshot($uid,'forms_update',$input);$guard->apply($uid,'forms_update',$input,$snapshot['revision'],bin2hex(random_bytes(16)),$snapshot['file_manifest']);$result=['native_edit'=>in_array('edit',$baseline,true),'native_results'=>in_array('results',$baseline,true),'read_id'=>$read['id'],'response_id'=>$response['id'],'updated'=>Server::get(\OCA\Forms\Db\FormMapper::class)->findById($id)->getDescription()==='Circle editor'];
 }else{throw new RuntimeException('Unknown audience case');}
 echo json_encode(['ok'=>true,'result'=>$result]);
}catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'class'=>$e::class]);}
