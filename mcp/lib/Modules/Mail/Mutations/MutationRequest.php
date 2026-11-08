<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Mutations;
/** Strict internal effect schema; no arbitrary native options, credentials or content. */
final class MutationRequest {
    public static function valid(array $r):bool {
        $keys=['account_id','message_id','mailbox_id','mailbox','generation','uid','flag','value','profile_revision','cache_revision'];
        if(count($r)!==count($keys)||array_diff(array_keys($r),$keys)!==[])return false;
        foreach(['account_id','message_id','mailbox_id'] as $key)if(!is_int($r[$key])||$r[$key]<1)return false;
        foreach(['generation','uid'] as $key)if(!is_string($r[$key])||preg_match('/^[1-9][0-9]{0,9}$/D',$r[$key])!==1||(float)$r[$key]>4294967295)return false;
        foreach(['mailbox','profile_revision','cache_revision'] as $key)if(!is_string($r[$key])||$r[$key]===''||strlen($r[$key])>1024)return false;
        return in_array($r['flag'],['seen','flagged'],true)&&is_bool($r['value']);
    }
}
