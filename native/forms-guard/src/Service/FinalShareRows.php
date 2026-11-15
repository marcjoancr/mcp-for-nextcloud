<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Exact relational data predicates. Native handlers remain the only permission policy algorithm. */
final class FinalShareRows {
    public const COLUMNS='id,share_type,share_with,uid_owner,uid_initiator,file_source,item_type,parent,permissions,accepted,expiration,attributes,stime';
    public static function id(string $full):int {
        if (!preg_match('/^ocinternal:([1-9][0-9]*)$/D',$full,$match) || (string)(int)$match[1]!==$match[1]) {throw new \RuntimeException('Final sharing provider identity is invalid');}
        return (int)$match[1];
    }
    public static function original(array $actual,array $record):void {
        if (!isset($record['core_state']) || $actual!==$record['core_state']) {throw new \RuntimeException('Final sharing original tuple changed');}
        $tuple=$record['approved_tuple'];
        if ((int)$actual['id']!==self::id($record['files_share_id']) || (int)$actual['share_type']!==$tuple['type'] || $actual['share_with']!==$tuple['recipient'] || $actual['uid_owner']!==$tuple['owner_id'] || (int)$actual['file_source']!==(int)$record['folder_id'] || $actual['item_type']!=='folder' || (int)$actual['permissions']!==1) {throw new \RuntimeException('Final sharing original identity changed');}
    }
    public static function target(array $actual,array $record,array $mapping,array $job):void {
        if ((int)$actual['id']!==self::id($mapping['files_share_id']) || (int)$actual['share_type']!==$record['approved_tuple']['type'] || $actual['share_with']!==$record['approved_tuple']['recipient'] || $actual['uid_owner']!==$job['input']['owner_id'] || $actual['uid_initiator']!==$job['uid'] || (int)$actual['file_source']!==(int)$mapping['folder_id'] || $actual['item_type']!=='folder' || (int)$actual['permissions']!==1 || (int)($actual['parent']??0)!==0) {throw new \RuntimeException('Final sharing target identity changed');}
        foreach (['accepted','attributes'] as $key) {if ($actual[$key]!==$record['share_state'][$key]) {throw new \RuntimeException('Final sharing recipient decision changed');}}
        if ($record['share_state']['expiration']!==null && ($actual['expiration']===null || new \DateTimeImmutable($actual['expiration'])>new \DateTimeImmutable($record['share_state']['expiration']))) {throw new \RuntimeException('Final sharing expiry widened');}
    }
    public static function child(array $actual,array $source,int $targetId,array $parent,array $job):void {
        foreach (['share_with','accepted','permissions','attributes'] as $key) {if ($actual[$key]!==$source[$key]) {throw new \RuntimeException('Final sharing child decision changed');}}
        $expiry=$source['expiration'];
        if ($parent['expiration']!==null && ($expiry===null || new \DateTimeImmutable($expiry)>new \DateTimeImmutable($parent['expiration']))) {$expiry=$parent['expiration'];}
        if ((int)$actual['id']!==$targetId || (int)$actual['share_type']!==2 || (int)$actual['parent']!==(int)$parent['id'] || $actual['uid_owner']!==$job['input']['owner_id'] || $actual['uid_initiator']!==$job['uid'] || (int)$actual['file_source']!==(int)$parent['file_source'] || $actual['item_type']!=='folder' || $actual['expiration']!==$expiry) {throw new \RuntimeException('Final sharing child identity changed');}
    }
    public static function form(array $actual,array $original,string $target):void {
        $expected=$original;$expected['owner_id']=$target;
        foreach (['locked_by','locked_until','file_id','file_format'] as $key) {$expected[$key]=null;}
        if ($actual!==$expected || (int)$actual['state']===2) {throw new \RuntimeException('Final sharing intended owner transition changed');}
    }
}
