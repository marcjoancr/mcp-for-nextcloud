<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\{Server,IDBConnection};
/** Forms-owned lifetime authority. Never resolves an MCP service or grants access to its archives. */
final class NativeHistoryIdentity {
 public const WITNESS='mcp_history_incarnation';
 public static function ready():bool{return Server::get(IDBConnection::class)->executeQuery('SELECT version FROM *PREFIX*migrations WHERE app=? AND version=?',['forms','050402Date20261115010000'])->fetchOne()!==false;}
 private static function row(string $uid):?array {
  if($uid===''||str_contains($uid,"\0")||!mb_check_encoding($uid,'UTF-8')){throw new \RuntimeException('Invalid native history principal');}
  $r=Server::get(IDBConnection::class)->executeQuery('SELECT uid,generation,legacy_actor FROM *PREFIX*forms_mcp_incarnations WHERE uid_hash=?',[hash('sha256',"forms-user\0".$uid)])->fetch();if(!$r){return null;}if($r['uid']!==$uid||!preg_match('/^[a-f0-9]{64}$/D',$r['generation'])){throw new \RuntimeException('Native history incarnation is corrupt');}return $r;
 }
 private static function user(string $uid):void {self::lifecycleSources();new FreshCore($uid);$c=Server::get(\OCP\Config\IUserConfig::class);$c->clearCache($uid,false);if($c->getValueString($uid,'core','deleted','false')==='true'){throw new \RuntimeException('Native history principal deletion is pending');}}
 public static function assertCurrent(string $uid,string $generation):void {
  if(!self::ready()||!preg_match('/^[a-f0-9]{64}$/D',$generation)){throw new \RuntimeException('Native history incarnation is unavailable');}self::user($uid);$a=self::row($uid);$c=Server::get(\OCP\Config\IUserConfig::class);$c->clearCache($uid,false);$w=$c->getValueString($uid,'forms',self::WITNESS,'');$b=self::row($uid);self::user($uid);
  if($a===null||$a!==$b||!hash_equals($generation,$a['generation'])||!hash_equals($generation,$w)){throw new \RuntimeException('Native history principal incarnation changed');}
 }
 /** Authorized enrollment only. Creation events never inherit a previous legacy bridge. */
 public static function capture(string $uid):string {
  if(!self::ready()){throw new \RuntimeException('Native history incarnation migration is required');}self::user($uid);$r=self::row($uid);
  if($r===null){$db=Server::get(IDBConnection::class);$own=!$db->inTransaction();if($own){$db->beginTransaction();}try{$r=self::row($uid);if($r===null){$generation=bin2hex(random_bytes(32));$legacy=self::legacy($uid);$db->executeStatement('INSERT INTO *PREFIX*forms_mcp_incarnations(uid_hash,uid,generation,legacy_actor) VALUES(?,?,?,?)',[hash('sha256',"forms-user\0".$uid),$uid,$generation,$legacy]);self::witness($uid,$generation);$r=self::row($uid);}if($own){$db->commit();}}catch(\Throwable$e){if($own&&$db->inTransaction()){$db->rollBack();}throw $e;}}
  self::assertCurrent($uid,$r['generation']);return $r['generation'];
 }
 private static function lifecycleSources():void {foreach([\OC\User\Manager::class=>'4d70b669ede78e84e646e5df63c103b9a910c7b65a18083c24ba153ab4fa6c29',\OCP\User\Events\BeforeUserCreatedEvent::class=>'d72b0ac18f78286a8c9e1019f5819e0392f906476ad6edb96be31085861b93cf']as$class=>$hash){$file=(new \ReflectionClass($class))->getFileName();if($file===false||is_link($file)||!hash_equals($hash,hash_file('sha256',$file))){throw new \RuntimeException('Native history lifecycle source is unqualified');}}}
 private static function witness(string $uid,string $token):void {$c=Server::get(\OCP\Config\IUserConfig::class);if(!$c->setValueString($uid,'forms',self::WITNESS,$token,false,\OCP\Config\IUserConfig::FLAG_SENSITIVE|\OCP\Config\IUserConfig::FLAG_INTERNAL)){throw new \RuntimeException('Native history witness was not persisted');}$c->clearCache($uid,false);}
 /** Exact legacy schema bridge, read-only; unknown/missing witness never becomes enrollment. */
 private static function legacy(string $uid):?string {
  $db=Server::get(IDBConnection::class);$prefix=Server::get(\OCP\IConfig::class)->getSystemValueString('dbtableprefix','oc_');if(!preg_match('/^[a-zA-Z0-9_]*$/D',$prefix)){throw new \RuntimeException('Legacy history database prefix is unqualified');}$schema=$db->getDatabaseProvider(true)==='postgres'?'current_schema()':'DATABASE()';if($db->executeQuery('SELECT 1 FROM information_schema.tables WHERE table_schema = '.$schema.' AND table_name = ?',[$prefix.'mcp_actor_incarnations'])->fetchOne()===false){return null;}try{$a=$db->executeQuery('SELECT actor_uid,incarnation FROM *PREFIX*mcp_actor_incarnations WHERE actor_key=?',[hash('sha256',"user\0".$uid)])->fetch();}catch(\OCP\DB\Exception){return null;}
  if(!$a||$a['actor_uid']!==$uid||!preg_match('/^[a-f0-9]{64}$/D',$a['incarnation'])){return null;}$c=Server::get(\OCP\Config\IUserConfig::class);$c->clearCache($uid,false);$w=$c->getValueString($uid,'mcp','operation_actor_incarnation','');$b=$db->executeQuery('SELECT actor_uid,incarnation FROM *PREFIX*mcp_actor_incarnations WHERE actor_key=?',[hash('sha256',"user\0".$uid)])->fetch();self::user($uid);return $a===$b&&hash_equals($a['incarnation'],$w)?$a['incarnation']:null;
 }
 public static function assertLegacy(string $uid,string $actor):void {
  self::user($uid);if(!preg_match('/^[a-f0-9]{64}$/D',$actor)||!hash_equals($actor,self::legacy($uid)??'')){throw new \RuntimeException('Legacy historical principal incarnation changed');}
  if(self::ready()&&($r=self::row($uid))!==null){self::assertCurrent($uid,$r['generation']);if($r['legacy_actor']!==$actor){throw new \RuntimeException('Legacy history belongs to a retired native generation');}}
 }
 /** No Forms fence or MCP policy mutex: account creation touches this one private row and witness only. */
 public static function beforeCreate(string $uid):void {
  if(!self::ready()){return;}self::lifecycleSources();if(!\OCA\Forms\Mcp\SourceIdentity::verify()){throw new \RuntimeException('Native history lifecycle source is unqualified');}$db=Server::get(IDBConnection::class);$own=!$db->inTransaction();if($own){$db->beginTransaction();}
  try{$token=bin2hex(random_bytes(32));$r=self::row($uid);if($r===null){$db->executeStatement('INSERT INTO *PREFIX*forms_mcp_incarnations(uid_hash,uid,generation,legacy_actor) VALUES(?,?,?,NULL)',[hash('sha256',"forms-user\0".$uid),$uid,$token]);}else{$db->executeStatement('UPDATE *PREFIX*forms_mcp_incarnations SET generation=?,legacy_actor=NULL WHERE uid_hash=?',[$token,hash('sha256',"forms-user\0".$uid)]);}self::witness($uid,$token);if($own){$db->commit();}}catch(\Throwable$e){if($own&&$db->inTransaction()){$db->rollBack();}throw $e;}
 }
}
