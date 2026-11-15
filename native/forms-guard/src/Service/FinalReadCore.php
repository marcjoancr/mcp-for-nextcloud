<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Attach the recorder only to fresh private native instances; preserve native algorithms. */
final class FinalReadCore {
 private static function set(object$o,string$key,mixed$value):void{(new \ReflectionProperty($o,$key))->setValue($o,$value);}
 public static function backend(\OC\User\Database $backend,FinalReadConnection$db):void {
  if($backend::class!==\OC\User\Database::class||(new \ReflectionProperty($backend,'table'))->getValue($backend)!=='users')throw new \RuntimeException('Final native user backend is unqualified');
  self::set($backend,'dbConnection',$db);self::set($backend,'cache',new \OCP\Cache\CappedMemoryCache());
 }
 public static function fresh(string$uid,FinalReadConnection$db):FreshCore {
  $core=new FreshCore($uid);$user=$core->user();self::backend($user->getBackend(),$db);self::set($user,'enabled',null);
  $config=$core->userConfig();self::set($config,'connection',$db);self::set($config,'fastCache',new \OCP\Cache\CappedMemoryCache());self::set($config,'lazyCache',new \OCP\Cache\CappedMemoryCache());
  $apps=$core->appConfig();self::set($apps,'connection',$db);
  foreach(['fastCache','lazyCache','valueTypes','configLexiconDetails','strictnessApplied']as$field)self::set($apps,$field,[]);
  self::set($apps,'fastLoaded',false);self::set($apps,'lazyLoaded',false);self::set($apps,'appVersionsCache',null);
  // FreshConfigCache remains the already qualified no-stale-read private cache.
  foreach($core->groups()->getBackends()as$backend){if($backend::class!==\OC\Group\Database::class)throw new \RuntimeException('Final native group backend is unqualified');self::set($backend,'dbConn',$db);self::set($backend,'groupCache',[]);}
  self::set($core->groups(),'cachedGroups',[]);self::set($core->groups(),'cachedUserGroups',[]);
  if(!$user->getBackend()->userExists($uid)||!$user->isEnabled())throw new \RuntimeException('Final native principal disappeared');
  return$core;
 }
 /** Same owned CommentsRuntime principal algorithm, with its actual row recorded. Values remain process-private. */
 public static function principal(string$uid,FinalReadConnection$db):string {
  $result=$db->executeQuery('SELECT uid,password FROM *PREFIX*users WHERE uid = ?',[$uid]);
  try{$row=$result->fetchAssociative();$extra=$result->fetchAssociative();}finally{$result->closeCursor();}
  if($row===false||$extra!==false)throw new \RuntimeException('Final native principal disappeared');
  return hash_hmac('sha256',json_encode($row,JSON_THROW_ON_ERROR),\OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('secret'));
 }
}
