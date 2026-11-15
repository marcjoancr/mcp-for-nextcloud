<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Own lifecycle transaction on the Forms advisory lock. No Forms outbox drains or app services. */
final class GroupLease {
    private static bool $busy=false;
    public static function run(\OCP\IDBConnection $db,\Closure $work):mixed {
        if (self::$busy || $db::class!==\OC\DB\ConnectionAdapter::class || $db->inTransaction()) {throw new \RuntimeException('Group observer requires its own transaction');}
        $provider=$db->getDatabaseProvider(true);
        if (!in_array($provider,['postgres','mariadb'],true)) {throw new \RuntimeException('Group observer database is unqualified');}
        $config=\OC::$server->get(\OCP\IConfig::class);$options=$config->getSystemValue('dbdriveroptions',[]);
        if ($config->getSystemValue('dbpersistent',false) || !is_array($options) || !empty($options[\PDO::ATTR_PERSISTENT])) {throw new \RuntimeException('Group observer persistent connection is unqualified');}
        $scalar=static function(string $sql,array $params=[])use($db):mixed {$r=$db->executeQuery($sql,$params);try{return $r->fetchOne();}finally{$r->closeCursor();}};
        $session=static fn():string=>(string)$scalar($provider==='postgres'?'SELECT pg_backend_pid()':'SELECT CONNECTION_ID()');
        $id=$session();$owned=$provider==='postgres'?(int)$scalar("SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND classid = 1179603533 AND objid = 1 AND objsubid = 2 AND granted")>0:(string)$scalar('SELECT IS_USED_LOCK(?)',['forms_mcp_revision_v1'])===$id;
        if ($owned) {throw new \RuntimeException('Group observer cannot borrow a primary lease');}
        self::$busy=true;$acquired=false;
        try {
            return GroupLeaseFlow::run(
                static function()use($provider,$scalar,&$acquired):void {
                    if ($provider==='postgres') {
                        $until=microtime(true)+60;
                        do {$acquired=(string)$scalar('SELECT CASE WHEN pg_try_advisory_lock(1179603533, 1) THEN 1 ELSE 0 END')==='1';if(!$acquired){if(microtime(true)>=$until)throw new \RuntimeException('Group observer lease timed out');usleep(50000);}} while (!$acquired);
                    } else {$acquired=(string)$scalar('SELECT GET_LOCK(?, 60)',['forms_mcp_revision_v1'])==='1';}
                    if (!$acquired) {throw new \RuntimeException('Group observer primary lease changed');}
                },
                static function()use($session,$id):void {if($session()!==$id)throw new \RuntimeException('Group observer primary session changed');GroupBootstrap::assertActive();},
                static function()use($db):void {$db->beginTransaction();},$work,
                static function()use($db):void {$db->commit();},
                static function()use($provider,$scalar,&$acquired):void {
                    // Acquisition may have succeeded even if its acknowledgement was lost.
                    $released=$provider==='postgres'?$scalar('SELECT CASE WHEN pg_advisory_unlock(1179603533, 1) THEN 1 ELSE 0 END'):$scalar('SELECT RELEASE_LOCK(?)',['forms_mcp_revision_v1']);
                    if ($acquired && (string)$released!=='1') {throw new \RuntimeException('Group observer primary release failed');}
                },
                static fn():bool=>$db->inTransaction(),
                static function()use($db):void {$db->rollBack();},
                static function()use($db):void {$db->close();}
            );
        } finally {self::$busy=false;}
    }
}
