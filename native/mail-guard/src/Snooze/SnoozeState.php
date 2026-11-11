<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Snooze;
/** Unknown wire ownership is terminal even when death preceded the actual command. */
final class SnoozeState {
    public static function releasable(array $r):bool {return $r['state']!=='uncertain'&&!in_array('wire_may_start',[$r['placement_phase'],$r['wake_phase']],true);}
    public static function cancellable(array $r):bool {return self::releasable($r)&&in_array($r['state'],['placing','snoozed'],true)&&$r['wake_phase']==='prepared';}
    public static function executable(array $r):bool {return $r['state']==='snoozed'&&in_array($r['placement_phase'],['remote_verified','cache_pending','completed'],true)&&$r['wake_phase']==='prepared';}
    public static function visible(array $r):string {return !self::releasable($r)?'uncertain':$r['state'];}
    public static function origin(array $r):array {return ['operationKeyHash'=>$r['operation_key'],'accountId'=>(int)$r['account_id'],'connectionId'=>(int)$r['connection_id'],'sourceTool'=>'mail_snooze','sourceMailboxId'=>(int)$r['source_mailbox_id'],'snoozeMailboxId'=>(int)$r['snooze_mailbox_id']];}
    public static function receipt(array $r,?int $message=null):array {
        return ['id'=>$r['id'],'revision'=>(string)$r['revision'],'state'=>self::visible($r),'accountId'=>(int)$r['account_id'],'wakeAt'=>(int)$r['wake_at'],'timezone'=>$r['timezone'],
            'original'=>['mailboxId'=>(int)$r['source_mailbox_id'],'generation'=>$r['source_generation'],'uid'=>$r['source_uid']],
            'current'=>['mailboxId'=>(int)$r['current_mailbox_id'],'generation'=>$r['current_generation'],'uid'=>$r['current_uid']],
            'currentMessageId'=>$message,'placementPhase'=>$r['placement_phase'],'wakePhase'=>$r['wake_phase'],'reason'=>$r['reason'],'safe_to_retry'=>false];
    }
}
