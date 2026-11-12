<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Transmission;
final class DeliveryState {
    public static function allows(string $from,string $to):bool {return in_array($to,match($from){'queued'=>['blocked','attempting','cancelled'],'blocked'=>['cancelled'],'attempting'=>['accepted','uncertain'],default=>[]},true);}
    public static function admission(string $uid,int $draftId,string $approvedRevision,string $operationKey,int $connectionId,string $sourceTool,int $sendAt,string $timezone,?array $approvedSentTarget=null):string {return hash('sha256',json_encode([$uid,$draftId,$approvedRevision,$operationKey,$connectionId,$sourceTool,$sendAt,$timezone,$approvedSentTarget],JSON_THROW_ON_ERROR));}
    public static function receipt(array $r):array {return ['id'=>$r['id'],'draftId'=>(int)$r['draft_id'],'accountId'=>(int)$r['account_id'],'revision'=>$r['revision'],'state'=>$r['state']==='attempting'?'uncertain':$r['state'],'sentState'=>$r['sent_state']==='attempting'?'uncertain':$r['sent_state'],'sendAt'=>(int)$r['send_at'],'timezone'=>$r['timezone'],'createdAt'=>(int)$r['created_at']];}
}
