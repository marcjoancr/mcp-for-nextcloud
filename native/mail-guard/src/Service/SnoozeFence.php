<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCA\Mail\Snooze\{SnoozeStore,SnoozeSource,SnoozeState};
/** Native final adapter binds one persisted descriptor and its physical leases. No caller chooses a target. */
final class SnoozeFence implements MutationFence {
    private bool $used=false;
    public function __construct(private SnoozeStore $store,private SnoozeSource $source,private array $row,private string $leg,private array $request,private object $authorization,private \Closure $leases) {
        if(!in_array($leg,['placement','wake'],true)||json_decode($row[$leg.'_request'],true,32,JSON_THROW_ON_ERROR)!==$request||$store->find($row['user_id'],$row['id'])!=$row)throw new \RuntimeException('Snooze leg binding changed.');
    }
    private function current():void {($this->leases)();$this->store->assertAccount($this->row['user_id'],(int)$this->row['account_id']);try{$this->authorization->authorize($this->row['user_id'],SnoozeState::origin($this->row));}catch(\OCA\Mail\Transmission\BeforeWireBlock $e){throw $e;}catch(\Throwable $e){throw new \OCA\Mail\Snooze\SnoozePolicyUnknown('Current snooze policy is unavailable.',0,$e);}$this->source->verify($this->row);($this->leases)();}
    public function assertPermit(string $uid,array $request):void {
        if($this->row['active_source']===null||$this->row['state']!==($this->leg==='placement'?'placing':'snoozed')||$this->used||$uid!==$this->row['user_id']||$request!==$this->request||$this->row[$this->leg.'_phase']!=='prepared')throw new \RuntimeException('Snooze MOVE permit is not reusable.');$this->current();$this->used=true;
    }
    public function beforePreconditions(array $binding):bool {$this->current();return $binding===$this->request;}
    public function beforeWire(array $binding):bool {
        if(!$this->used||$binding!==$this->request||$this->row[$this->leg.'_phase']!=='prepared')return false;
        $this->current();$this->row=$this->store->update($this->row,[$this->leg.'_phase'=>'wire_may_start','state'=>$this->leg==='placement'?'placing':'waking'],fn()=>$this->current());return true;
    }
    public function recordRemote(array $proof):bool {
        ($this->leases)();$r=$this->request;$keys=['outcome','message_id','mailbox_id','generation','uid','destination_mailbox_id','destination_generation','destination_uid','action'];
        if($this->row[$this->leg.'_phase']!=='wire_may_start'||count($proof)!==count($keys)||array_diff(array_keys($proof),$keys)!==[]||($proof['outcome']??null)!=='moved')return false;
        foreach(['message_id','mailbox_id','generation','uid','destination_mailbox_id','destination_generation','action']as$key)if(($proof[$key]??null)!==$r[$key])return false;
        if(\OCA\Mail\IMAP\MoveProof::copyUid([$proof['destination_generation'],$proof['uid'],$proof['destination_uid']],$r['uid'],$r['destination_generation'])===null)return false;
        // Proof persistence must not depend on policy after the already-admitted remote effect.
        $changes=[$this->leg.'_phase'=>'remote_verified',$this->leg.'_proof'=>json_encode($proof,JSON_THROW_ON_ERROR),'state'=>$this->leg==='placement'?'snoozed':'woken','current_mailbox_id'=>$proof['destination_mailbox_id'],'current_generation'=>$proof['destination_generation'],'current_uid'=>$proof['destination_uid']];
        if($this->leg==='wake')$changes['active_source']=null;$this->row=$this->store->update($this->row,$changes);return true;
    }
    public function beforeCache(array $binding):bool {
        if($binding!==$this->request||$this->row[$this->leg.'_phase']!=='remote_verified')return false;$this->current();$this->row=$this->store->update($this->row,[$this->leg.'_phase'=>'cache_pending']);return true;
    }
    public function recordCache(array $result):bool {
        ($this->leases)();$proof=json_decode($this->row[$this->leg.'_proof'],true,32,JSON_THROW_ON_ERROR);
        $expected=['state'=>'source_removed','message_id'=>$this->request['message_id'],'destination_mailbox_id'=>$this->request['destination_mailbox_id'],'destination_uid'=>$proof['destination_uid'],'destination_state'=>'native_sync_pending'];
        if($this->row[$this->leg.'_phase']!=='cache_pending'||$result!==$expected)return false;$this->row=$this->store->update($this->row,[$this->leg.'_phase'=>'completed']);return true;
    }
}
