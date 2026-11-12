<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Mutations;
/** Closed native tag request/proofs. No caller-supplied flag, native command or options. */
final class TagMutationRequest {
    public static function keyword(mixed $label):bool {
        return is_string($label)&&$label===strtolower($label)&&$label!==''&&strlen($label)<=64&&preg_match('/[\x00-\x20\x7f-\xff(){}%*"\\\\\]]/D',$label)===0
            &&!in_array(strtolower($label),['junk','nonjunk','notjunk','$junk','$notjunk','$phishing','$forwarded','$mdnsent','important','$important','$labelimportant','$labelwork','$labelpersonal','$labeltodo','$labellater'],true);
    }
    public static function valid(array $r):bool {
        $keys=['kind','account_id','message_id','mailbox_id','mailbox','generation','uid','profile_revision','cache_revision','tag_id','imap_label','tag_revision','tag_cache_revision','value'];
        if(count($r)!==count($keys)||array_diff(array_keys($r),$keys)!==[]||$r['kind']!=='tag'||!is_int($r['tag_id'])||$r['tag_id']<1||!self::keyword($r['imap_label']))return false;
        foreach(['tag_revision','tag_cache_revision']as$key)if(!is_string($r[$key])||preg_match('/^[0-9a-f]{64}$/D',$r[$key])!==1)return false;
        $base=$r;foreach(['kind','tag_id','imap_label','tag_revision','tag_cache_revision']as$key)unset($base[$key]);$base['flag']='seen';return MutationRequest::valid($base);
    }
    public static function remote(array $r,array $p):bool {
        if(count($p)!==7||!in_array($p['outcome']??null,['verified','already_desired'],true))return false;
        foreach(['message_id','mailbox_id','generation','uid','tag_id','value']as$key)if(($p[$key]??null)!==$r[$key])return false;return true;
    }
    public static function cache(array $r,array $p):bool {
        if(count($p)!==4||($p['state']??null)!=='updated')return false;foreach(['message_id','tag_id','value']as$key)if(($p[$key]??null)!==$r[$key])return false;return true;
    }
}
