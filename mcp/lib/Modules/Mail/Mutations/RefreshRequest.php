<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Mutations;
/** Closed refresh request and bounded, content-free receipts. */
final class RefreshRequest {
    public static function valid(array $r):bool {
        $keys=['kind','account_id','mailbox_id','mailbox','profile_revision','cache_revision','allow_cache_reset'];if(count($r)!==count($keys)||array_diff(array_keys($r),$keys)!==[]||$r['kind']!=='refresh'||!is_bool($r['allow_cache_reset']))return false;foreach(['account_id','mailbox_id']as$k)if(!is_int($r[$k])||$r[$k]<1)return false;foreach(['profile_revision','cache_revision']as$k)if(!is_string($r[$k])||preg_match('/^[0-9a-f]{64}$/D',$r[$k])!==1)return false;return is_string($r['mailbox'])&&$r['mailbox']!==''&&strlen($r['mailbox'])<=1024&&preg_match('/[\x00\r\n]/',$r['mailbox'])===0;
    }
    public static function remote(array $r,array $p):bool {return count($p)===5&&($p['outcome']??null)==='observed'&&self::proof($r,$p);}
    public static function cache(array $r,array $p):bool {return count($p)===7&&($p['state']??null)==='refreshed'&&self::proof($r,$p)&&is_int($p['observed_at']??null)&&$p['observed_at']>0&&($p['threads_state']??null)==='native_rebuild_pending';}
    private static function proof(array $r,array $p):bool {return ($p['mailbox_id']??null)===$r['mailbox_id']&&is_string($p['generation']??null)&&preg_match('/^[1-9][0-9]{0,9}$/D',$p['generation'])===1&&(float)$p['generation']<=4294967295&&is_int($p['messages']??null)&&$p['messages']>=0&&$p['messages']<=200&&is_bool($p['reset']??null)&&(!$p['reset']||$r['allow_cache_reset']);}
}
