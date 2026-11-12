<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Operations;

use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Current original-action access is checked again; receipts never contain message bodies or attachment handles. */
final class MailOperationReader {
    public function __construct(private OperationJournal $journal, private \Closure $allowsTool,private ?\Closure $nativeEntry=null,private ?\Closure $deliveryEntry=null,private ?\Closure $snoozeEntry=null,private ?\Closure $sieveEntry=null,private ?\OCA\Mcp\Modules\Mail\Access\MailFreshAccess $fresh=null) {}
    public function get(string $uid, string $id): array {
        $request=$this->projectionFence($uid,'mail_get_operation');
        $receipt = $this->journal->get($uid, $id);
        if ($receipt === null) throw new ToolFailure('Operation not found.');
        $historical=$this->historicalFence($uid,$receipt,['mail_get_operation']);
        $result=['operation'=>$receipt];
        if($receipt['state']==='awaiting_user'&&$this->nativeEntry!==null){$entry=($this->nativeEntry)($uid,$id);if($entry!==null)$result['native_setup']=$entry;}
        if(in_array($receipt['tool'],['mail_send','mail_schedule'],true)&&$this->deliveryEntry!==null){$entry=($this->deliveryEntry)($uid,$id,$receipt['tool']);if($entry!==null)$result['delivery']=$entry;}
        if($receipt['tool']==='mail_snooze'&&$this->snoozeEntry!==null){$entry=($this->snoozeEntry)($uid,$id);if($entry!==null)$result['snooze']=$entry;}
        if(in_array($receipt['tool'],['mail_write_filters','mail_transmit_filters','mail_write_absence','mail_enable_absence','mail_disable_absence'],true)&&$this->sieveEntry!==null){$entry=($this->sieveEntry)($uid,$id,$receipt['tool']);if($entry!==null)$result['sieve']=$entry;}
        $historical();$request();return $result;
    }
    public function assertAccess(string $uid,array $receipt): void {
        ($this->historicalFence($uid,$receipt))();
    }
    private function projectionFence(string $uid,string $tool):\Closure {
        if($this->fresh===null)throw new ToolFailure('Fresh Mail projection unavailable.');
        return $this->fresh->projectionFence($uid,$tool);
    }
    private function historicalFence(string $uid,array $receipt,array $extra=[]):\Closure {
        if($this->fresh===null)throw new ToolFailure('Fresh Mail projection unavailable.');
        $tools=array_merge($extra,isset($receipt['source_tool'])?[$receipt['source_tool']]:[],$receipt['source_tools']??[]);
        $fresh=$this->fresh->historicalFence($uid,$receipt['operation_id'],$receipt['tool'],$tools);
        // Existing request-local checks may narrow access, but only the fresh fence can authorize projection.
        return function()use($uid,$receipt,$tools,$fresh):void {
            $fresh();
            foreach(array_merge([$receipt['tool']],$tools)as$tool)if(!($this->allowsTool)($uid,$tool))throw new ToolFailure('Operation not found.');
        };
    }
    public function list(string $uid, int $limit, int $offset): array {
        $request=$this->projectionFence($uid,'mail_list_operations');
        $visible=[];$fences=[];$wanted=$offset+$limit+1;
        for($cursor=0;$cursor<=10000&&count($visible)<$wanted;$cursor+=50){
            $page=$this->journal->page($uid,50,$cursor);$rows=$page['operations'];
            foreach($rows as $r){try{$fences[$r['operation_id']]=$this->historicalFence($uid,$r,['mail_list_operations']);$visible[]=$r;}catch(ToolFailure){}}
            if($page['scanned']<50)break;
        }
        $projected=[];foreach($visible as$row){try{$fences[$row['operation_id']]();$projected[]=$row;}catch(ToolFailure){}}$request();return ['operations'=>array_slice($projected,$offset,$limit),'next_offset'=>count($projected)>$offset+$limit?$offset+$limit:null];
    }
}
