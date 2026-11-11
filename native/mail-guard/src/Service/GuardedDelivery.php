<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCA\Mail\Transmission\{AttemptStore,DeliveryState,DeliverySource,RawSmtp,BeforeWireBlock};
use OCP\IConfig;
/** Closed native admission/dispatch. A completed journal entry proves admission only. */
final class GuardedDelivery {
    public function __construct(private AttemptStore $store,private DeliverySource $source,private FrozenMessageRenderer $renderer,private RawSmtp $smtp,private SentCopyRepair $sent,private IConfig $config,private \OCP\EventDispatcher\IEventDispatcher $events){}
    private function enabled():void {if(!$this->config->getSystemValueBool('app.mail.mcp-transmission.enabled',false))throw new \RuntimeException('Approved transmission is disabled.');}
    public function preview(string $uid,int $draftId):array {return $this->store->coordinator()->runOwner($uid,function()use($uid,$draftId):array {$content=$this->source->read($uid,$draftId);return $this->renderer->preview($content)+['sentTarget'=>$this->sent->approvalTarget($uid,$content['accountId'])];});}
    public function queue(string $uid,int $draftId,string $approvedRevision,string $operationKey,int $connectionId,string $sourceTool,int $sendAt,string $timezone,?array $approvedSentTarget=null):array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$approvedRevision)||!preg_match('/^[\x21-\x7e]{1,128}$/D',$operationKey)||$connectionId<1||!in_array($sourceTool,['mail_send','mail_schedule'],true)||!in_array($timezone,\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),true)||$sendAt<0||($sourceTool==='mail_send'&&$sendAt!==0)||($sourceTool==='mail_schedule'&&$sendAt<=0))throw new \RuntimeException('Invalid delivery approval.');
        if($approvedSentTarget!==null&&(count($approvedSentTarget)!==2||!is_int($approvedSentTarget['mailboxId']??null)||$approvedSentTarget['mailboxId']<1||!is_string($approvedSentTarget['revision']??null)||preg_match('/^[a-f0-9]{64}$/D',$approvedSentTarget['revision'])!==1))throw new \RuntimeException('Invalid approved Sent target.');
        $revision=DeliveryState::admission($uid,$draftId,$approvedRevision,$operationKey,$connectionId,$sourceTool,$sendAt,$timezone,$approvedSentTarget);
        return $this->store->coordinator()->runOwner($uid,function()use($uid,$draftId,$approvedRevision,$operationKey,$connectionId,$sourceTool,$sendAt,$timezone,$revision,$approvedSentTarget):array {
            $prior=$this->store->replay($uid,$operationKey);if($prior!==null){$this->store->assertAccount($uid,(int)$prior['account_id']);if(!hash_equals($prior['revision'],$revision))throw new \RuntimeException('Operation key belongs to a different approval.');return DeliveryState::receipt($prior);}
            $this->enabled();$this->store->assertFree($uid,$draftId);$content=$this->source->read($uid,$draftId);
            if(!hash_equals($approvedRevision,$this->renderer->revision($content)))throw new \RuntimeException('Approved draft or sender changed.');
            if($approvedSentTarget!==null&&$this->sent->approvalTarget($uid,$content['accountId'])!==$approvedSentTarget)throw new \RuntimeException('Approved Sent target changed.');
            $this->store->assertAccount($uid,(int)$content['accountId']);$this->smtp->check($this->source->account($uid,$content['accountId']));$artifact=$this->renderer->freeze($content);$artifact['sentTarget']=$approvedSentTarget;
            $row=['id'=>bin2hex(random_bytes(32)),'user_id'=>$uid,'draft_id'=>$draftId,'account_id'=>$content['accountId'],'alias_id'=>$content['aliasId'],'operation_key'=>hash('sha256',$operationKey),'revision'=>$revision,'content_revision'=>$approvedRevision,'connection_id'=>$connectionId,'source_tool'=>$sourceTool,'send_at'=>$sendAt,'timezone'=>$timezone,'created_at'=>time(),'state'=>'queued','sent_state'=>'not_started','artifact'=>json_encode($artifact,JSON_THROW_ON_ERROR),'sent_proof'=>'{}'];
            $this->store->insert($row);return DeliveryState::receipt($row);
        });
    }
    public function findByOperation(string $uid,string $operationKey):?array {$r=$this->store->replay($uid,$operationKey);return $r===null?null:DeliveryState::receipt($r);}
    public function accessContext(string $uid,string $id):array {$r=$this->store->metadata($uid,$id);return ['accountId'=>(int)$r['account_id'],'connectionId'=>(int)$r['connection_id'],'sourceTool'=>$r['source_tool'],'draftId'=>(int)$r['draft_id']];}
    public function status(string $uid,string $id):array {return DeliveryState::receipt($this->store->metadata($uid,$id));}
    public function due(int $limit=50):array {if(!$this->config->getSystemValueBool('app.mail.mcp-transmission.enabled',false))return [];return $this->store->due($limit);}
    public function queuedAfter(string $cursor,int $limit=25):array {return $this->store->queuedAfter($cursor,$limit);}
    public function hasUnresolved(string $uid,int $accountId):bool {return $this->store->hasUnresolved($uid,$accountId);}
    public function cancel(string $uid,string $id,string $revision):array {
        return $this->store->coordinator()->runOwner($uid,function()use($uid,$id,$revision):array {$r=$this->store->find($uid,$id);if(!hash_equals($r['revision'],$revision))throw new \RuntimeException('Delivery approval changed.');if($r['state']!=='cancelled'&&!$this->store->transition($r,'cancelled'))throw new \RuntimeException('Transmission may already have started; cancellation is too late.');return $this->status($uid,$id);});
    }
    /** Definite withdrawn authorization before claim is terminal and releases local native holds. No transport exists. */
    public function blockBeforeWire(string $uid,string $id):array {
        return $this->store->coordinator()->runOwner($uid,function()use($uid,$id):array {$r=$this->store->metadata($uid,$id);if($r['state']==='queued')$this->store->transition($r,'blocked');return $this->status($uid,$id);});
    }
    public function dispatch(string $uid,string $id,object $fence):array {
        return $this->store->coordinator()->runOwner($uid,function($scope)use($uid,$id,$fence):array {
            $r=$this->store->metadata($uid,$id);if($r['state']!=='queued'||(int)$r['send_at']>time()||!$this->config->getSystemValueBool('app.mail.mcp-transmission.enabled',false))return DeliveryState::receipt($r);
            $r=$this->store->find($uid,$id);
            $authorize=function()use($r,$uid,$fence,$scope):void {$scope->assertOwned();$this->store->assertAccount($uid,(int)$r['account_id']);$this->enabled();$fence->authorize($uid,(int)$r['connection_id'],$r['source_tool'],(int)$r['account_id']);$fresh=$this->source->read($uid,(int)$r['draft_id']);if(!hash_equals($r['content_revision'],$this->renderer->revision($fresh)))throw new BeforeWireBlock('Current delivery identity changed.');$this->store->assertAccount($uid,(int)$r['account_id']);$this->smtp->check($this->source->account($uid,(int)$r['account_id']));$scope->assertOwned();};
            try{$authorize();$content=$this->source->read($uid,(int)$r['draft_id']);if(!hash_equals($r['content_revision'],$this->renderer->revision($content)))throw new BeforeWireBlock('Source changed.');$account=$this->source->account($uid,(int)$r['account_id']);$this->smtp->check($account);$artifact=json_decode($r['artifact'],true,512,JSON_THROW_ON_ERROR);if(!hash_equals($artifact['smtpHash'],hash('sha256',$artifact['smtp']))||!hash_equals($artifact['fccHash'],hash('sha256',$artifact['fcc'])))throw new BeforeWireBlock('Artifact changed.');}
            catch(BeforeWireBlock){$this->store->transition($r,'blocked');return $this->status($uid,$id);}
            catch(\Throwable){return $this->status($uid,$id);}
            // No transport exists yet. Unknown commit acknowledgement escapes, so no wire effect occurs.
            $attempt=array_replace($r,['state'=>'attempting']);
            $outcome=\OCA\Mail\Transmission\DispatchAttempt::run(
                fn():bool=>$this->store->transition($r,'attempting'),
                fn()=>$this->smtp->send($account,$artifact,$authorize),
                fn(string $state):bool=>$this->store->transition($attempt,$state),
            );
            if($outcome==='accepted'&&is_array($artifact['sentTarget']??null)){try{$this->sent->copyApproved($uid,$id,$artifact['sentTarget'],$fence);}catch(\Throwable){/* Accepted remains accepted; changed/unavailable target stays pending. */}}
            if($outcome==='accepted'){try{$eventMessage=clone $this->source->message($uid,(int)$r['draft_id']);$eventMessage->setRaw($artifact['fcc']);$this->events->dispatchTyped(new \OCA\Mail\Events\MessageSentEvent($account,$eventMessage));}catch(\Throwable){/* Ancillary listeners never change durable SMTP evidence. */}}
            if($outcome==='uncertain')return DeliveryState::receipt(array_replace($r,['state'=>'uncertain']));
            return $this->status($uid,$id);
        });
    }
}
