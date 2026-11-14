<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';
use OCP\Server;use OCA\Forms\Mcp\Service\OriginContext;
$uid='guard-control-frame';$policy=null;$code=0;
try {
 Server::get(\OCP\App\IAppManager::class)->loadApp('forms');Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
 $policy=Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
 if(($argv[1]??'')==='revoke'){$policy->setGrant($uid,'forms','continue_operations',false);echo "committed\n";exit;}
 $users=Server::get(\OCP\IUserManager::class);$user=$users->get($uid)??$users->createUser($uid,bin2hex(random_bytes(24)));Server::get(\OCP\IUserSession::class)->setUser($user);
 require '/tmp/origin-fixture.php';Server::get(\OCP\IConfig::class)->deleteUserValue($uid,'mcp','forms_native_test_ready');
 $original=formsNativeOrigin($uid,'forms_delete_responses');$independent=formsNativeOrigin($uid,'forms_create');
 $control=Server::get(\OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization::class)->capture($uid,'forms_continue_operation',[],['bulk_authority'=>['tool'=>$original['tool'],'required_operations'=>$original['operations'],'files_operations'=>$original['references']['files_operations']??[]]]);
 $checks=OriginContext::within($uid,$control,'lookup',[],fn()=>OriginContext::withBulkControl('forms_continue_operation',$original,fn()=>OriginContext::within($uid,$original,'forms_delete_responses',[],function()use($uid,$original,$independent,$control):array{
  $checks=[];
  OriginContext::assertFresh();
  $key=bin2hex(random_bytes(32));
  try{\OCA\Forms\Mcp\Service\EffectEnvelope::put($key,['context'=>OriginContext::current()]);$persisted=\OCA\Forms\Mcp\Service\EffectEnvelope::get($key);$checks['deferred_envelope_keeps_control_request_local']=!str_contains(json_encode($persisted,JSON_THROW_ON_ERROR),json_encode($control,JSON_THROW_ON_ERROR));}
  finally{Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('forms_mcp_effects')->getFolder($key)->delete();}
  $p=proc_open([PHP_BINARY,__FILE__,'revoke'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0||trim($out)!=='committed'){throw new RuntimeException('Independent policy commit failed '.$err);}
  foreach(['identical_descriptor'=>$original,'different_descriptor'=>$independent]as$name=>$origin){
   $allowed=true;try{OriginContext::within($uid,$origin,'effect',[],static fn()=>OriginContext::assertFresh());}catch(Throwable){$allowed=false;}$checks['independent_'.$name]=$allowed;
   $denied=false;try{OriginContext::assertFresh();}catch(Throwable){$denied=true;}$checks['parent_restored_'.$name]=$denied;
  }
  $denied=false;try{OriginContext::withFileImpact('forms_delete_responses',['files'=>[]],static fn()=>OriginContext::assertFresh());}catch(Throwable){$denied=true;}$checks['same_business_file_impact_retains_control']=$denied;return $checks;
 })));
 $checks['outer_context_restored']=!OriginContext::active();$ordinary=true;try{OriginContext::within($uid,$original,'effect',[],static fn()=>OriginContext::assertFresh());}catch(Throwable){$ordinary=false;}$checks['subsequent_origin_independent']=$ordinary;
 echo json_encode(['ok'=>!in_array(false,$checks,true),'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){$code=1;echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'trace'=>$e->getTraceAsString()],JSON_THROW_ON_ERROR)."\n";}
finally{if($policy!==null){$policy->setGrant($uid,'forms','continue_operations',true);}}
exit($code);
