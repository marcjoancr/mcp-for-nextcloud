<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** The two pinned providers share Core's integer primary-key sequence, never a provider-local cursor. */
final class CoreShareIdentity {
    public const PROFILE='core-share-pk-v1';
    public static function parse(string $full,?int $type=null):array {
        if(!preg_match('/^(ocinternal|ocCircleShare):([1-9][0-9]*)$/D',$full,$parts)
            ||(string)(int)$parts[2]!==$parts[2]||(int)$parts[2]<1)
            throw new \RuntimeException('Managed Forms share identity is unavailable');
        if($type!==null&&($parts[1]==='ocCircleShare'?$type!==7:!in_array($type,[0,1,2],true)))
            throw new \RuntimeException('Managed Forms share provider and type differ');
        return ['provider'=>$parts[1],'id'=>(int)$parts[2]];
    }
    public static function id(string $full,?int $type=null):int{return self::parse($full,$type)['id'];}
    /** Identifiers are closed server aliases; no tool-supplied SQL is accepted. */
    public static function sql(\OCP\IDBConnection $db,string $alias):string {
        if(!in_array($alias,['x','m'],true))throw new \RuntimeException('Managed Forms share SQL alias is unavailable');
        $type=match($db->getDatabaseProvider(true)){'postgres'=>'BIGINT','mariadb'=>'SIGNED',default=>throw new \RuntimeException('Managed Forms share database is unavailable')};
        $field=$alias.'.files_share_id';
        return "CASE WHEN $field LIKE 'ocinternal:%' THEN CAST(SUBSTRING($field,12) AS $type) WHEN $field LIKE 'ocCircleShare:%' THEN CAST(SUBSTRING($field,15) AS $type) ELSE NULL END";
    }
}
