<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Mutations;
/** Closed internal folder effect and receipt schemas; no native options or message content. */
final class MailboxMutationRequest {
    public static function valid(array $r):bool {
        $keys=['kind','account_id','action','mailbox_id','name','new_name','delimiter','subscribed','tree','profile_revision','inventory_revision'];
        if(count($r)!==count($keys)||array_diff(array_keys($r),$keys)!==[]||$r['kind']!=='mailbox'||!in_array($r['action'],['create_mailbox','rename_mailbox','subscribe_mailbox'],true)||!is_int($r['account_id'])||$r['account_id']<1||!is_int($r['mailbox_id'])||$r['mailbox_id']<($r['action']==='create_mailbox'?0:1))return false;
        foreach(['name','new_name','delimiter','profile_revision','inventory_revision'] as $key)if(!is_string($r[$key])||strlen($r[$key])>1024)return false;
        if(strlen($r['delimiter'])!==1||$r['new_name']===''||($r['action']==='subscribe_mailbox'?!is_bool($r['subscribed']):$r['subscribed']!==null)||!is_array($r['tree'])||!array_is_list($r['tree'])||count($r['tree'])>101)return false;
        foreach($r['tree'] as $b){$k=['id','name','delimiter','selectable','shared','special','subscribed','generation','cache_revision'];if(!is_array($b)||count($b)!==9||array_diff(array_keys($b),$k)!==[]||!is_int($b['id'])||$b['id']<1||!is_string($b['name'])||strlen($b['name'])>255||!is_string($b['delimiter'])||strlen($b['delimiter'])!==1||!is_bool($b['selectable'])||!is_bool($b['shared'])||!is_bool($b['subscribed'])||!is_array($b['special'])||count($b['special'])>16||!is_string($b['cache_revision'])||strlen($b['cache_revision'])>128||!self::generation($b['generation']))return false;foreach($b['special'] as $s)if(!is_string($s)||strlen($s)>128)return false;}
        return true;
    }
    private static function generation(mixed $v):bool{return is_string($v)&&preg_match('/^[1-9][0-9]{0,9}$/D',$v)===1&&(float)$v<=4294967295;}
    public static function remote(array $r,array $p):bool {
        if(count($p)!==6||array_diff(array_keys($p),['outcome','action','account_id','mailbox_id','name','folders'])!==[])return false;
        foreach(['action','account_id','mailbox_id'] as $key)if(($p[$key]??null)!==$r[$key])return false;
        if(($p['name']??null)!==$r['new_name']||!in_array($p['outcome']??null,match($r['action']){'create_mailbox'=>['created'],'rename_mailbox'=>['renamed'],default=>['subscribed','already_desired']},true)||!is_array($p['folders']??null)||!array_is_list($p['folders']))return false;
        $expected=self::targets($r);if(count($p['folders'])!==count($expected))return false;$seen=[];
        foreach($p['folders'] as $b){if(!is_array($b)||count($b)!==3||array_diff(array_keys($b),['name','generation','subscribed'])!==[]||!is_string($b['name'])||!array_key_exists($b['name'],$expected)||isset($seen[$b['name']])||!self::generation($b['generation'])||!is_bool($b['subscribed']))return false;$seen[$b['name']]=true;$old=$expected[$b['name']];if($old!==null&&(($old['generation']!==$b['generation'])||$b['subscribed']!==($r['action']==='subscribe_mailbox'?$r['subscribed']:$old['subscribed'])))return false;}
        return true;
    }
    public static function targets(array $r):array {
        if($r['action']==='create_mailbox')return [$r['new_name']=>null];$out=[];foreach($r['tree'] as $b)$out[$r['action']==='rename_mailbox'?$r['new_name'].substr($b['name'],strlen($r['name'])):$b['name']]=$b;return $out;
    }
    public static function cache(array $r,array $p):bool {
        if(count($p)!==3||($p['state']??null)!=='updated'||($p['account_id']??null)!==$r['account_id']||!is_array($p['mailboxes']??null)||!array_is_list($p['mailboxes'])||count($p['mailboxes'])!==count(self::targets($r)))return false;$expected=self::targets($r);$seen=[];$ids=[];
        foreach($p['mailboxes'] as $b){if(!is_array($b)||count($b)!==2||!is_int($b['id']??null)||$b['id']<1||!is_string($b['name']??null)||!array_key_exists($b['name'],$expected)||isset($seen[$b['name']])||isset($ids[$b['id']]))return false;$seen[$b['name']]=true;$ids[$b['id']]=true;}
        return true;
    }
}
