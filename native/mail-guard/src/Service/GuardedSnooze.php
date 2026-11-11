<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCA\Mail\Snooze\{SnoozeStore,SnoozeSource,SnoozeState};
use OCA\Mail\Sync\MailboxLease;
use OCA\Mail\Transmission\BeforeWireBlock;
use OCP\{IDBConnection,IConfig};
/** One-message intent. Stock executable snooze tables and SMTP are never used. */
final class GuardedSnooze {
    public function __construct(private SnoozeStore $store,private SnoozeSource $source,private GuardedMessageMutations $moves,private IDBConnection $db,private IConfig $config){}
    private function pair(array $r,\Closure $work):mixed {
        $this->store->coordinator()->scope()->assertOwned();$ids=[(int)$r['source_mailbox_id'],(int)$r['snooze_mailbox_id']];sort($ids,SORT_NUMERIC);$locks=[];
        try{foreach($ids as$id)$locks[]=MailboxLease::acquire($this->db,$this->config->getSystemValueString('dbtableprefix','oc_'),$id,$this->config);$assert=function()use(&$locks):void{$this->store->coordinator()->scope()->assertOwned();foreach($locks as$l)$l->assertOwned();};$assert();return $work($assert);}finally{foreach(array_reverse($locks)as$l)$l->release();}
    }
    public function preview(string $uid,int $messageId,int $wakeAt,string $timezone,object $fence):array {return $this->store->coordinator()->runOwner($uid,fn()=>$this->source->preview($uid,$messageId,$wakeAt,$timezone,$fence));}
    public function schedule(string $uid,int $messageId,int $wakeAt,string $timezone,string $approvedRevision,string $operationKey,int $connectionId,object $fence):array {
        if($connectionId<1||preg_match('/^[a-f0-9]{64}$/D',$operationKey)!==1||preg_match('/^[a-f0-9]{64}$/D',$approvedRevision)!==1)throw new \InvalidArgumentException('Snooze requires a persistent original connection and exact approval.');
        $approval=hash('sha256',json_encode([$uid,$messageId,$wakeAt,$timezone,$approvedRevision,$operationKey,$connectionId],JSON_THROW_ON_ERROR));
        return $this->store->coordinator()->runOwner($uid,function()use($uid,$messageId,$wakeAt,$timezone,$approvedRevision,$operationKey,$connectionId,$fence,$approval):array {
            $prior=$this->store->replay($uid,$operationKey);if($prior!==null){$fence->authorize($uid,SnoozeState::origin($prior));if($prior['approval']!==$approval)throw new \RuntimeException('Snooze operation key already belongs to another approval.');return SnoozeState::receipt($prior);}
            $p=$this->source->preview($uid,$messageId,$wakeAt,$timezone,$fence);$r=$p['request'];
            return $this->pair(['source_mailbox_id'=>$r['mailbox_id'],'snooze_mailbox_id'=>$r['destination_mailbox_id']],function()use($uid,$messageId,$wakeAt,$timezone,$approvedRevision,$operationKey,$connectionId,$fence,$approval):array {
                $p=$this->source->preview($uid,$messageId,$wakeAt,$timezone,$fence);if($p['revision']!==$approvedRevision)throw new \RuntimeException('Snooze approval changed.');$r=$p['request'];
                $row=['id'=>bin2hex(random_bytes(32)),'user_id'=>$uid,'account_id'=>$r['account_id'],'connection_id'=>$connectionId,'operation_key'=>hash('sha256',$operationKey),'approval'=>$approval,'revision'=>1,'created_at'=>time(),'wake_at'=>$wakeAt,'timezone'=>$timezone,'state'=>'placing','placement_phase'=>'prepared','wake_phase'=>'prepared','source_mailbox_id'=>$r['mailbox_id'],'source_name'=>$r['mailbox'],'source_generation'=>$r['generation'],'source_uid'=>$r['uid'],'source_message_id'=>$r['message_id'],'snooze_mailbox_id'=>$r['destination_mailbox_id'],'snooze_name'=>$r['destination_mailbox'],'snooze_generation'=>$r['destination_generation'],'current_mailbox_id'=>$r['mailbox_id'],'current_generation'=>$r['generation'],'current_uid'=>$r['uid'],'profile_revision'=>$r['profile_revision'],'roles_revision'=>$r['roles_revision'],'placement_request'=>json_encode($r,JSON_THROW_ON_ERROR),'wake_request'=>'{}','placement_proof'=>'{}','wake_proof'=>'{}','reason'=>'','active_source'=>hash('sha256',json_encode([$uid,$r['account_id'],$r['mailbox_id'],$r['generation'],$r['uid']],JSON_THROW_ON_ERROR))];
                $this->store->insert($row,fn()=>$fence->authorize($uid,SnoozeState::origin($row)));return SnoozeState::receipt($row);
            });
        });
    }
    public function accessContext(string $uid,string $id):array {return SnoozeState::origin($this->store->metadata($uid,$id));}
    public function findByOperation(string $uid,string $key):?array {$r=$this->store->replay($uid,$key);return $r===null?null:SnoozeState::receipt($r);}
    public function status(string $uid,string $id,object $fence):array {$r=$this->store->find($uid,$id);$fence->authorize($uid,SnoozeState::origin($r));$message=null;if(SnoozeState::releasable($r))try{$message=$this->source->resolve($r);}catch(\Throwable){}return SnoozeState::receipt($r,$message);}
    public function previewWake(string $uid,string $id,object $fence):array {
        return $this->store->coordinator()->runOwner($uid,function()use($uid,$id,$fence):array{$r=$this->store->find($uid,$id);$fence->authorize($uid,SnoozeState::origin($r));if(!SnoozeState::executable($r))throw new \RuntimeException('Snooze is not eligible for a new wake.');$request=$this->source->wake($r);$out=SnoozeState::receipt($r,$request['message_id']??null);$out['readiness']=$request===null?'waiting_cache':'ready';$out['wakeRevision']=hash('sha256',json_encode([$id,(int)$r['revision'],$request],JSON_THROW_ON_ERROR));return $out;});
    }
    public function cancel(string $uid,string $id,string $approvedRevision,object $fence):array {
        return $this->store->coordinator()->runOwner($uid,function()use($uid,$id,$approvedRevision,$fence):array{$r=$this->store->find($uid,$id);$authorize=fn()=>$fence->authorize($uid,SnoozeState::origin($r));$authorize();if((string)$r['revision']!==$approvedRevision)throw new \RuntimeException('Snooze cancellation approval changed.');return SnoozeState::receipt($this->store->cancel($r,$authorize));});
    }
    public function place(string $uid,string $id,object $fence):array {return $this->execute($uid,$id,'placement',null,$fence);}
    /** null revision denotes the original due-time approval; explicit wake requires its fresh preview revision. */
    public function wake(string $uid,string $id,?string $approvedRevision,object $fence):array {return $this->execute($uid,$id,'wake',$approvedRevision,$fence);}
    private function execute(string $uid,string $id,string $leg,?string $revision,object $fence):array {
        return $this->store->coordinator()->runOwner($uid,function()use($uid,$id,$leg,$revision,$fence):array {
            $r=$this->store->find($uid,$id);if($leg==='placement'?($r['state']!=='placing'||$r['placement_phase']!=='prepared'):(!SnoozeState::executable($r)||($revision===null&&(int)$r['wake_at']>time())))return SnoozeState::receipt($r);
            return $this->pair($r,function(\Closure $leases)use($uid,$id,$leg,$revision,$fence,$r):array {
                try{
                    $fence->authorize($uid,SnoozeState::origin($r));$this->store->assertAccount($uid,(int)$r['account_id']);$this->source->verify($r);
                    if($leg==='placement')$request=json_decode($r['placement_request'],true,32,JSON_THROW_ON_ERROR);
                    else {$request=$this->source->wake($r);if($request===null)return SnoozeState::receipt($r)+['readiness'=>'waiting_cache'];$expected=hash('sha256',json_encode([$id,(int)$r['revision'],$request],JSON_THROW_ON_ERROR));if($revision!==null&&!hash_equals($expected,$revision))throw new \RuntimeException('Wake approval changed.');$r=$this->store->update($r,['wake_request'=>json_encode($request,JSON_THROW_ON_ERROR)],fn()=>$fence->authorize($uid,SnoozeState::origin($r)));}
                    $native=new SnoozeFence($this->store,$this->source,$r,$leg,$request,$fence,$leases);
                    try{$this->moves->move($uid,$request,$native);}catch(\OCA\Mail\Snooze\SnoozePolicyUnknown $e){throw $e;}catch(BeforeWireBlock $e){throw $e;}catch(\Throwable $e){
                        $fresh=$this->store->find($uid,$id);if($fresh[$leg.'_phase']==='prepared')$this->store->update($fresh,['state'=>'blocked',$leg.'_phase'=>'failed_before_effect','active_source'=>null,'reason'=>'provider_or_cache_refused']);throw $e;
                    }
                }catch(BeforeWireBlock){$fresh=$this->store->find($uid,$id);$this->store->block($fresh);}catch(\Throwable){/* Independent native proof remains authoritative. Never repeat wire/cache/events. */}
                return SnoozeState::receipt($this->store->find($uid,$id));
            });
        });
    }
    public function review(string $uid,string $id,object $fence):array {
        return $this->store->coordinator()->runOwner($uid,function()use($uid,$id,$fence):array{$r=$this->store->metadata($uid,$id);if(!$fence->allows($uid,SnoozeState::origin($r)))$r=$this->store->block($r);return SnoozeState::receipt($r);});
    }
    public function due(int $limit=25,string $cursor=''):array {return $this->store->due($limit,$cursor);}
    public function queuedAfter(string $cursor,int $limit=25):array {return $this->store->queuedAfter($cursor,$limit);}
}
