<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Offline source/profile checks. The parent host controller proves process/network quiescence separately. */
final class GroupInstallProfile {
 public static function read(string$root,string$path):array{
  if($root!==realpath($root)||$path!==realpath($path)||is_link($path)||!is_file($path)||filesize($path)>1048576)throw new \RuntimeException('Installer profile unavailable');
  $value=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
  if(!is_array($value)||array_keys($value)!==['format','root','action','operation','source_pins','config_pins','migration_versions','prior_epoch','next_epoch','bundle_manifest','bundle_preimages','maintenance_prior']||$value['format']!==1||$value['root']!==$root||!in_array($value['action'],['install','validate','rollback'],true)||preg_match('/^[a-f0-9]{32}$/D',(string)$value['operation'])!==1||!is_bool($value['maintenance_prior']))throw new \RuntimeException('Installer profile malformed');
  self::pins($root,$value['source_pins']);self::pins($root.'/config',$value['config_pins']);
  self::complete($root.'/config',$value['config_pins']);
  foreach(['custom_apps/forms','custom_apps/mcp','apps/circles']as$scope){$sub=[];foreach($value['source_pins']as$name=>$hash)if(str_starts_with($name,$scope.'/'))$sub[substr($name,strlen($scope)+1)]=$hash;self::complete($root.'/'.$scope,$sub);}
  if(!array_is_list($value['migration_versions'])||count($value['migration_versions'])>1000)throw new \RuntimeException('Installer migration profile malformed');
  foreach($value['migration_versions']as$version)if(!is_string($version)||preg_match('/^[A-Za-z0-9]{1,64}$/D',$version)!==1)throw new \RuntimeException('Installer migration profile malformed');
  if(count(array_unique($value['migration_versions']))!==count($value['migration_versions']))throw new \RuntimeException('Installer migration profile malformed');
  GroupInstallState::epochAction($value['prior_epoch'],$value['next_epoch']);GroupInstallState::metadata($value['next_epoch'],$value['bundle_manifest']);return$value;
 }
 public static function pins(string$root,array$pins):void{
  if($pins===[]||count($pins)>10000||!is_dir($root)||is_link($root))throw new \RuntimeException('Installer source inventory unavailable');
  foreach($pins as$path=>$hash){
   if(!is_string($path)||preg_match('#^[A-Za-z0-9_.@ -]+(?:/[A-Za-z0-9_.@ -]+)*$#D',$path)!==1||in_array('..',explode('/',$path),true)||!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1)throw new \RuntimeException('Installer source inventory malformed');
   $file=$root.'/'.$path;$parts=explode('/',$path);$current=$root;foreach($parts as$part){$current.='/'.$part;if(is_link($current))throw new \RuntimeException('Installer symbolic source refused');}
   if(!is_file($file)||!hash_equals($hash,hash_file('sha256',$file)))throw new \RuntimeException('Installer source changed');
  }
 }
 public static function complete(string$root,array$pins):void{
  if($pins===[]||is_link($root)||!is_dir($root))throw new \RuntimeException('Installer complete inventory unavailable');$actual=[];
  foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST)as$file){if($file->isLink()||(!$file->isFile()&&!$file->isDir()))throw new \RuntimeException('Installer inventory special source');if($file->isFile()){$actual[]=substr($file->getPathname(),strlen($root)+1);if(count($actual)>10000)throw new \RuntimeException('Installer inventory bound');}}
  $expected=array_keys($pins);sort($actual,SORT_STRING);sort($expected,SORT_STRING);if($actual!==$expected)throw new \RuntimeException('Installer complete inventory differs');
 }
 public static function helper():void{
  if(PHP_SAPI!=='cli'||getmypid()===1||posix_getppid()!==1||posix_geteuid()!==33||(string)ini_get('opcache.preload')!==''||getenv('FRANKENPHP_WORKER')!==false)throw new \RuntimeException('Installer finite helper profile unavailable');
  $cmd=@file_get_contents('/proc/1/cmdline');if($cmd!=="/usr/local/bin/php\0/installer/GroupInstallController.php\0")throw new \RuntimeException('Installer finite controller unavailable');
  foreach(glob('/proc/[0-9]*/stat')as$path){$pid=(int)basename(dirname($path));if(in_array($pid,[1,getmypid()],true))continue;$value=@file_get_contents($path);if($value===false)continue;$end=strrpos($value,') ');if($end===false||substr($value,$end+2,1)!=='Z')throw new \RuntimeException('Installer foreign helper process');}
 }
}
