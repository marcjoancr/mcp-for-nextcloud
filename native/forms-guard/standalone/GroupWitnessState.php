<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace McpFormsServerExtension;
/** Closed scalar state. No Core group existence inference reactivates retired/unfinished witnesses. */
final class GroupWitnessState {
    private static function identity(string $gid,string $epoch,string $generation):void {
        if ($gid==='' || !mb_check_encoding($gid,'UTF-8') || mb_strlen($gid,'UTF-8')>255 || str_contains($gid,"\0") || preg_match('/^[a-f0-9]{64}$/D',$epoch)!==1 || preg_match('/^[a-f0-9]{64}$/D',$generation)!==1) {throw new \RuntimeException('Group witness identity is invalid');}
    }
    private static function row(?array $row,string $gid):void {
        if ($row!==null && (array_keys($row)!==['gid_key','gid','epoch','generation','state'] || $row['gid']!==$gid || $row['gid_key']!==hash('sha256',$gid) || !in_array($row['state'],['creating','live','retired'],true) || preg_match('/^[a-f0-9]{64}$/D',(string)$row['generation'])!==1 || preg_match('/^[a-f0-9]{64}$/D',(string)$row['epoch'])!==1)) {throw new \RuntimeException('Group witness row binding changed');}
    }
    private static function make(string $gid,string $epoch,string $generation,string $state):array {
        self::identity($gid,$epoch,$generation);return ['gid_key'=>hash('sha256',$gid),'gid'=>$gid,'epoch'=>$epoch,'generation'=>$generation,'state'=>$state];
    }
    public static function enroll(?array $prior,string $gid,string $epoch,string $generation):array {
        self::identity($gid,$epoch,$generation);self::row($prior,$gid);
        if ($prior===null || $prior['epoch']!==$epoch) {return self::make($gid,$epoch,$generation,'live');}
        if ($prior['state']!=='live') {throw new \RuntimeException('Group lifetime requires reconciliation');}return $prior;
    }
    public static function beforeDelete(?array $prior,string $gid,string $epoch,string $generation):array {
        self::row($prior,$gid);return self::make($gid,$epoch,$generation,'retired');
    }
    public static function beforeCreate(?array $prior,string $gid,string $epoch,string $generation):array {
        self::row($prior,$gid);return self::make($gid,$epoch,$generation,'creating');
    }
    public static function afterCreate(?array $prior,string $gid,string $epoch,string $generation):array {
        self::identity($gid,$epoch,$generation);self::row($prior,$gid);
        if ($prior===null || $prior['epoch']!==$epoch || $prior['generation']!==$generation || $prior['state']!=='creating') {throw new \RuntimeException('Group creation intent changed');}
        return self::make($gid,$epoch,$generation,'live');
    }
    public static function assertLive(?array $row,string $gid,string $epoch,array $captured):void {
        self::row($row,$gid);
        if ($row===null || $row['state']!=='live' || $row['epoch']!==$epoch || $captured!==['epoch'=>$epoch,'generation'=>$row['generation']]) {throw new \RuntimeException('Group lifetime changed');}
    }
}
