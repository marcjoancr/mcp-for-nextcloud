<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Identity projection only: no membership or permission algorithm. */
final class GroupIdentity {
    public static function creation(object $backend,string $name):string {
        if ($backend::class!==\OC\Group\Database::class) {throw new \RuntimeException('Group creation backend is unqualified');}
        // Full Database.php bytes are in GroupSources. This pinned native method only
        // executes mb_strlen/hash and does not access its connection or any service.
        $method=new \ReflectionMethod($backend,'computeGid');
        if (!is_string($method->getFileName()) || hash_file('sha256',$method->getFileName())!=='a8f20a20b7837badde07b5c54c1c1c9f35b2307d02a53766952cd7dc2fb9dbd9' || $method->getStartLine()!==611 || $method->getEndLine()!==615 || $method->getDeclaringClass()->getName()!==\OC\Group\Database::class || !$method->isPrivate() || $method->isStatic() || $method->getNumberOfParameters()!==1 || (string)$method->getReturnType()!=='string') {throw new \RuntimeException('Group creation normalizer is unqualified');}
        return self::canonicalRow(['gid'=>$method->invoke($backend,$name)],false);
    }
    public static function canonicalRow(array|false $row,array|false $extra):string {
        if ($row===false || $extra!==false) {throw new \RuntimeException('Group canonical identity is unavailable');}
        if (array_keys($row)!==['gid'] || !is_string($row['gid']) || $row['gid']==='' || !mb_check_encoding($row['gid'],'UTF-8') || mb_strlen($row['gid'],'UTF-8')>255 || str_contains($row['gid'],"\0")) {throw new \RuntimeException('Group canonical identity is malformed');}
        return $row['gid'];
    }
    public static function existing(\OCP\IDBConnection $db,string $input):string {
        // Same native Database::groupExists/getGroupDetails/deleteGroup equality;
        // use the returned primary row, not an alias-sensitive PHP cache key.
        $result=$db->executeQuery('SELECT gid FROM *PREFIX*groups WHERE gid = ? LIMIT 2',[$input]);
        try {$row=$result->fetchAssociative();$extra=$result->fetchAssociative();}finally{$result->closeCursor();}
        return self::canonicalRow($row,$extra);
    }
    public static function captured(string $current,array $captured):array {
        if (array_keys($captured)!==['gid','epoch','generation'] || $captured['gid']!==$current) {throw new \RuntimeException('Group canonical identity changed');}
        return ['epoch'=>$captured['epoch'],'generation'=>$captured['generation']];
    }
}
