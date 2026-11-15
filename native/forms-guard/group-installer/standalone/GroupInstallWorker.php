<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
// Only the finite, source-pinned CLI controller invokes this file. It is not a native app route.
ob_start(static fn(string$bytes):string=>'');error_reporting(0);ini_set('display_errors','0');ini_set('log_errors','0');
$stage='admission';$ok=false;
try{
 if(PHP_SAPI!=='cli'||$argc!==3||!preg_match('/^[0-9]{2}\.json$/D',$argv[1])||!in_array($argv[2],['inspect','schema','stage','epoch','verify','retire','remove_entry','maintenance','maintenance_on','verify_inactive','snapshot'],true))throw new \RuntimeException('Installer worker input refused');
 foreach(['GroupInstallState','GroupInstallFiles','GroupInstallProfile','GroupInstallLiteralConfig']as$class)require_once __DIR__.'/'.$class.'.php';
 GroupInstallProfile::helper();$profilePath='/profiles/'.$argv[1];$raw=@file_get_contents($profilePath);if(!is_string($raw)||strlen($raw)>1048576)throw new \RuntimeException('Installer worker profile unavailable');
 $initial=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$root=$initial['root']??'';$p=GroupInstallProfile::read($root,$profilePath);$phase=$argv[2];$stage=$phase;
 $bundle='/bundle';$manifest=$bundle.'/GroupSources.json';if(is_link($manifest)||!hash_equals($p['bundle_manifest'],hash_file('sha256',$manifest)))throw new \RuntimeException('Installer bundle manifest changed');$pins=json_decode(file_get_contents($manifest),true,8,JSON_THROW_ON_ERROR);
 GroupInstallProfile::pins($root,$pins['native']);GroupInstallProfile::pins($bundle,$pins['owned']);
 $names=array_keys($pins['owned']);sort($names,SORT_STRING);$expected=['GroupBootstrap.php','GroupCoreService.php','GroupIdentity.php','GroupLease.php','GroupLeaseFlow.php','GroupObserver.php','GroupProfile.php','GroupRawRegistry.php','GroupWitnessState.php','GroupWitnessStore.php','mcp-forms-groups.config.php','mcp_forms_group_bootstrap.php'];sort($expected,SORT_STRING);if($names!==$expected)throw new \RuntimeException('Installer bundle inventory differs');
 $config=$root.'/config';$files=new GroupInstallFiles($config);$entry='mcp-forms-groups.config.php';$metadata='mcp_forms_group_install.json';
 $mainBefore=file_get_contents($config.'/config.php');GroupInstallLiteralConfig::read($mainBefore);
 $preimage=static function(string$name)use($p):?string{if(!array_key_exists($name,$p['bundle_preimages']))throw new \RuntimeException('Installer artifact preimage missing');return$p['bundle_preimages'][$name];};
 if($phase==='snapshot'){
  $literal=GroupInstallLiteralConfig::read($mainBefore);if(($literal['datadirectory']??null)!==$root.'/data'||isset($literal['objectstore']))throw new \RuntimeException('Installer local data profile unqualified');require_once __DIR__.'/GroupInstallTree.php';$proof=GroupInstallTree::snapshot($root.'/data');
 }elseif($phase==='stage'){
  if(!in_array($p['action'],['install','validate'],true))throw new \RuntimeException('Installer stage action refused');
  if($p['action']==='install'){
   foreach($expected as$name)if($name!==$entry)$files->atomic($name,file_get_contents($bundle.'/'.$name),$preimage($name));
   $files->atomic('GroupSources.json',file_get_contents($manifest),$preimage('GroupSources.json'));
  }else{GroupInstallProfile::pins($config,$pins['owned']);if(!hash_equals($p['bundle_manifest'],hash_file('sha256',$config.'/GroupSources.json')))throw new \RuntimeException('Installer validation bundle differs');}
  $bytes=json_encode(GroupInstallState::metadata($p['next_epoch'],$p['bundle_manifest']),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";$files->atomic($metadata,$bytes,$preimage($metadata));
  if($p['action']==='install')$files->atomic($entry,file_get_contents($bundle.'/'.$entry),$preimage($entry));
 }else{
  // First bootstrap and every shutdown effect is inside the host's full DB/source invariant interval.
  define('OC_CONSOLE',true);require $root.'/lib/base.php';
  if(file_get_contents($config.'/config.php')!==$mainBefore)throw new \RuntimeException('Installer bootstrap changed main config');
  if(\OC::$SERVERROOT!==$root||rtrim(\OC::$configDir,'/')!==$config)throw new \RuntimeException('Installer native root changed');
  $db=\OCP\Server::get(\OCP\IDBConnection::class);if($db::class!==\OC\DB\ConnectionAdapter::class||$db->getInner()::class!==\OC\DB\Connection::class||$db->getDatabaseProvider(true)!=='postgres'||$db->inTransaction())throw new \RuntimeException('Installer native database profile refused');
  $admission=static function()use($root,$p):void{GroupInstallProfile::helper();GroupInstallProfile::pins($root,$p['source_pins']);};
  if($phase==='schema'){
   if(!class_exists(GroupLeaseFlow::class,false))require_once '/bundle/GroupLeaseFlow.php';foreach(['GroupInstallLease','GroupInstallOutput','GroupInstallMigration']as$class)require_once __DIR__.'/'.$class.'.php';
   $version='/custom_apps/forms/lib/Migration/Version050402Date20261115020000.php';if(!array_key_exists(ltrim($version,'/'),$p['source_pins']))throw new \RuntimeException('Installer migration source not pinned');require_once$root.$version;
   $runner=new GroupInstallMigration($db->getInner(),new GroupInstallOutput(),new \Psr\Log\NullLogger());$changed=GroupInstallLease::run($db,$admission,fn():bool=>$runner->executeExact($p['migration_versions']));
  }elseif(in_array($phase,['epoch','retire'],true)){
   if(($phase==='retire')!==($p['action']==='rollback'))throw new \RuntimeException('Installer epoch action refused');
   require_once __DIR__.'/GroupInstallActivation.php';GroupBootstrap::assertActive();GroupInstallActivation::rotate($db,$p['prior_epoch'],$p['next_epoch'],$phase==='retire');
  }elseif($phase==='verify'){
   GroupBootstrap::assertActive();if(GroupWitnessStore::epoch($db)!==$p['next_epoch'])throw new \RuntimeException('Installer validation epoch differs');
  }elseif($phase==='remove_entry'){
   if($p['action']!=='rollback')throw new \RuntimeException('Installer removal action refused');GroupBootstrap::assertActive();
   $r=$db->executeQuery('SELECT id,epoch FROM *PREFIX*forms_mcp_group_epoch');try{$row=$r->fetchAssociative();$extra=$r->fetchAssociative();}finally{$r->closeCursor();}
   if($extra!==false||!in_array($row['id']??null,[1,'1'],true)||($row['epoch']??null)!==$p['next_epoch'])throw new \RuntimeException('Installer retirement not committed');
   $files->remove($entry,$preimage($entry)??throw new \RuntimeException('Installer entry preimage missing'));
  }elseif($phase==='verify_inactive'){
   if($p['action']!=='rollback'||file_exists($config.'/'.$entry))throw new \RuntimeException('Installer retirement entry remains');
   $r=$db->executeQuery('SELECT id,epoch FROM *PREFIX*forms_mcp_group_epoch');try{$row=$r->fetchAssociative();$extra=$r->fetchAssociative();}finally{$r->closeCursor();}
   if($extra!==false||!in_array($row['id']??null,[1,'1'],true)||($row['epoch']??null)!==$p['next_epoch'])throw new \RuntimeException('Installer retirement state differs');
  }elseif(in_array($phase,['maintenance','maintenance_on'],true)){
   $system=\OCP\Server::get(\OC\SystemConfig::class);$target=$phase==='maintenance_on'?true:$p['maintenance_prior'];$cache=[];foreach($system->getKeys()as$key)$cache[$key]=$system->getValue($key);$changed=!array_key_exists('maintenance',$cache)||$cache['maintenance']!==$target;$cache['maintenance']=$target;
   $maintenanceExpected=$changed?"<?php\n".\OC\Config::CONF_WARNING.'$CONFIG = '.var_export($cache,true).";\n":$mainBefore;
   $system->setValue('maintenance',$target);
  }elseif($phase!=='inspect')throw new \RuntimeException('Installer native phase refused');
  $admission();
 }
 $expectedMain=$maintenanceExpected??$mainBefore;
 if(file_get_contents($config.'/config.php')!==$expectedMain)throw new \RuntimeException('Installer unexpected main config effect');
 $ok=true;
}catch(\Throwable$e){$class=$e::class;$error=preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,255}$/D',$class)===1?$class:'UnknownFailure';}
while(ob_get_level()>0)ob_end_clean();
echo json_encode(['ok'=>$ok,'stage'=>$stage,'error_class'=>$ok?null:($error??'UnknownFailure')]+($ok?($proof??[]):[]),JSON_THROW_ON_ERROR),"\n";exit($ok?0:1);
