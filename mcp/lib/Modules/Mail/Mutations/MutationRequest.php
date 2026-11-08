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
        $move=isset($r['action']);
        $keys=['account_id','message_id','mailbox_id','mailbox','generation','uid','profile_revision','cache_revision'];
        $keys=array_merge($keys,$move?['action','destination_mailbox_id','destination_mailbox','destination_generation','destination_cache_revision','roles_revision','requires_trash_hook']:['flag','value']);
        if(count($r)!==count($keys)||array_diff(array_keys($r),$keys)!==[])return false;
        foreach(array_merge(['account_id','message_id','mailbox_id'],$move?['destination_mailbox_id']:[]) as $key)if(!is_int($r[$key])||$r[$key]<1)return false;
        foreach(array_merge(['generation','uid'],$move?['destination_generation']:[]) as $key)if(!is_string($r[$key])||preg_match('/^[1-9][0-9]{0,9}$/D',$r[$key])!==1||(float)$r[$key]>4294967295)return false;
        foreach(array_merge(['mailbox','profile_revision','cache_revision'],$move?['destination_mailbox','destination_cache_revision','roles_revision']:[]) as $key)if(!is_string($r[$key])||$r[$key]===''||strlen($r[$key])>1024)return false;
        if($move)return in_array($r['action'],['move','archive','trash','restore'],true)&&is_bool($r['requires_trash_hook'])&&$r['mailbox_id']!==$r['destination_mailbox_id']&&$r['mailbox']!==$r['destination_mailbox'];
        return in_array($r['flag'],['seen','flagged'],true)&&is_bool($r['value']);
    }
    public static function tool(array $r):string {
        return match($r['action']??null){null=>'mail_set_message_flag','move'=>'mail_move_message','archive'=>'mail_archive_message','trash'=>'mail_trash_message','restore'=>'mail_restore_message',default=>throw new \InvalidArgumentException('Invalid mutation action.')};
    }
}
