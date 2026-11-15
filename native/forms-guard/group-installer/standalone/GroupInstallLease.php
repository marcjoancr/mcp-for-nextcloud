<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Pre-publication schema lease only. Active epoch work uses the unchanged GroupLease. */
final class GroupInstallLease {
 private static bool $busy=false;
 public static function run(\OCP\IDBConnection$db,\Closure$admission,\Closure$work):mixed{
  $admission();if(self::$busy||$db::class!==\OC\DB\ConnectionAdapter::class||$db->inTransaction()||$db->getDatabaseProvider(true)!=='postgres')throw new \RuntimeException('Installer schema lease profile refused');
  $config=\OCP\Server::get(\OCP\IConfig::class);$options=$config->getSystemValue('dbdriveroptions',[]);
  if($config->getSystemValue('dbpersistent',false)||!is_array($options)||!empty($options[\PDO::ATTR_PERSISTENT]))throw new \RuntimeException('Installer persistent connection refused');
  $scalar=static function(string$sql)use($db):mixed{$r=$db->executeQuery($sql);try{return$r->fetchOne();}finally{$r->closeCursor();}};
  $id=(string)$scalar('SELECT pg_backend_pid()');if((int)$scalar("SELECT COUNT(*) FROM pg_locks WHERE locktype='advisory' AND pid=pg_backend_pid() AND classid=1179603533 AND objid=1 AND objsubid=2 AND granted")!==0)throw new \RuntimeException('Installer cannot borrow a lease');
  $acquired=false;self::$busy=true;
  $verify=static function()use($scalar,$id,$admission):void{$admission();if((string)$scalar('SELECT pg_backend_pid()')!==$id)throw new \RuntimeException('Installer primary session changed');};
  try{return GroupLeaseFlow::run(
   static function()use($scalar,&$acquired):void{$until=microtime(true)+60;do{$acquired=(string)$scalar('SELECT CASE WHEN pg_try_advisory_lock(1179603533,1) THEN 1 ELSE 0 END')==='1';if(!$acquired){if(microtime(true)>=$until)throw new \RuntimeException('Installer lease timed out');usleep(50000);}}while(!$acquired);},
   $verify,static function()use($db):void{$db->beginTransaction();},$work,static function()use($db):void{$db->commit();},
   static function()use($scalar,&$acquired):void{$released=$scalar('SELECT CASE WHEN pg_advisory_unlock(1179603533,1) THEN 1 ELSE 0 END');if($acquired&&(string)$released!=='1')throw new \RuntimeException('Installer lease release failed');},
   static fn():bool=>$db->inTransaction(),static function()use($db):void{$db->rollBack();},static function()use($db):void{$db->close();}
  );}finally{self::$busy=false;}
 }
}
