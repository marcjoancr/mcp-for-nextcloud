<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Only Forms-owned rows are written. Callers must hold the declared exact primary fence. */
final class GroupWitnessStore {
    public static function epoch(\OCP\IDBConnection $db):string {
        $expected=GroupProfile::assertCurrent();$r=$db->executeQuery('SELECT epoch FROM *PREFIX*forms_mcp_group_epoch WHERE id = 1');
        try {$row=$r->fetchAssociative();$extra=$r->fetchAssociative();}finally{$r->closeCursor();}
        if (!is_array($row) || $extra!==false || $row['epoch']!==$expected) {throw new \RuntimeException('Group observer installation epoch changed');}return $expected;
    }
    public static function read(\OCP\IDBConnection $db,string $gid):?array {
        $r=$db->executeQuery('SELECT gid_key,gid,epoch,generation,state FROM *PREFIX*forms_mcp_group_history WHERE gid_key = ?',[hash('sha256',$gid)]);
        try {$row=$r->fetchAssociative();$extra=$r->fetchAssociative();}finally{$r->closeCursor();}
        if ($extra!==false) {throw new \RuntimeException('Group witness identity is duplicated');}return $row===false?null:$row;
    }
    public static function write(\OCP\IDBConnection $db,?array $prior,array $row):void {
        if (!$db->inTransaction()) {throw new \RuntimeException('Group witness transaction is unavailable');}
        if ($prior===null) {$count=$db->executeStatement('INSERT INTO *PREFIX*forms_mcp_group_history (gid_key,gid,epoch,generation,state) VALUES (?,?,?,?,?)',array_values($row));}
        else {$count=$db->executeStatement('UPDATE *PREFIX*forms_mcp_group_history SET epoch = ?,generation = ?,state = ? WHERE gid_key = ? AND gid = ? AND epoch = ? AND generation = ? AND state = ?',[$row['epoch'],$row['generation'],$row['state'],$prior['gid_key'],$prior['gid'],$prior['epoch'],$prior['generation'],$prior['state']]);}
        if ($count!==1) {throw new \RuntimeException('Group witness update is uncertain');}
    }
}
