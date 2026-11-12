<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Transmission;

use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Operations\{OperationJournal,BeforeEffectFailure};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Integration\MailDeliveryGateway;
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\AppFramework\Utility\ITimeFactory;

/** Approval admits an immutable native entry. Admission success never asserts SMTP acceptance or delivery. */
class MailDeliveryOperations {
    public function __construct(private MailDeliveryGateway $gateway,private PlanState $states,private OperationJournal $journal,
        private ConnectionContext $connection,private ITimeFactory $clock,private \Closure $allowsTool,private ?DeliveryRunner $runner=null,private ?\Closure $assertAccount=null,private ?\Closure $assertDraft=null){}
    public function previewSend(string $uid,array $args):array {return $this->sendPlan($uid,$args,false);}
    public function previewSchedule(string $uid,array $args):array {return $this->sendPlan($uid,$args,true);}
    private function sendPlan(string $uid,array $args,bool $schedule):array {
        $id=$args['draft_id']??null;if(!is_int($id)||$id<1)throw new \InvalidArgumentException('Choose one owned local draft.');
        $at=$schedule?ScheduleInstant::parse((string)($args['send_at']??''),(string)($args['timezone']??''),$this->clock->getTime()):0;
        if($this->connection->current()<1)throw new ToolFailure('Mail transmission requires a persistent OAuth connection with its own tool selection.');
        $source=$schedule?'mail_schedule':'mail_send';
        if(!($this->allowsTool)($uid,$source))throw new ToolFailure('Mail action unavailable.');
        // Resolve only the owned draft identity before any private content.
        if($this->assertDraft!==null)($this->assertDraft)($uid,$id);
        $snapshot=$this->gateway->preview($uid,$id,$source);
        if($this->assertAccount!==null)($this->assertAccount)($uid,$snapshot['accountId']);
        $plan=['action'=>$schedule?'schedule_mail':'send_mail','draft'=>$snapshot,'send_at'=>$at,'timezone'=>$schedule?$args['timezone']:'UTC',
            'sending_enabled'=>$this->gateway->enabled(),'notice'=>'Approval releases a frozen copy for one attempt. SMTP acceptance is not delivery. An uncertain attempt is never automatically repeated.'];
        return $plan+['plan_state'=>$this->states->of($source,$uid,$plan)];
    }
    public function send(string $uid,array $args):array {return $this->admit($uid,$args,false);}
    public function schedule(string $uid,array $args):array {return $this->admit($uid,$args,true);}
    private function admit(string $uid,array $args,bool $schedule):array {
        PlanState::require($args);$plan=BeforeEffectFailure::preflight(fn()=>$this->sendPlan($uid,$args,$schedule));
        if(!PlanState::matches($plan['plan_state'],$args))throw new PlanChanged($plan);
        if(!$plan['sending_enabled'])throw new BeforeEffectFailure('MCP Mail transmission is disabled.');
        $source=$schedule?'mail_schedule':'mail_send';$draft=$plan['draft'];
        $this->journal->checkpoint(['account_id'=>$draft['accountId'],'draft_id'=>$draft['draftId']]);
        $key=hash('sha256',$this->journal->scopeKey($uid)."\0".$this->journal->activeId($uid));
        $receipt=$this->gateway->queue($uid,$draft['draftId'],$draft['revision'],$key,$this->connection->current(),$source,$plan['send_at'],$plan['timezone'],$draft['sentTarget']??null);
        $this->journal->checkpoint(['delivery_id'=>$receipt['id'],'account_id'=>$receipt['accountId'],'source_tool'=>$source]);
        return ['delivery'=>self::receipt($receipt),'safe_to_retry'=>false,'notice'=>'The immutable queue admission is recorded. Inspect delivery status; do not infer SMTP acceptance from this admission.'];
    }
    public function read(string $uid,string $id):array {
        $this->authorize($uid,$id);
        return ['delivery'=>self::receipt($this->gateway->status($uid,$id)),'safe_to_retry'=>false,'notice'=>'Accepted means SMTP server acceptance, not delivery. Status never dispatches or repairs.'];
    }
    /** A lost admission response is recovered by its original key; this cannot submit or repair anything. */
    public function recover(string $uid,string $operationId,string $sourceTool):?array {
        if(!in_array($sourceTool,['mail_send','mail_schedule'],true)||!($this->allowsTool)($uid,$sourceTool))throw new ToolFailure('Operation not found.');
        $key=hash('sha256',$this->journal->scopeKey($uid)."\0".$operationId);
        $entry=$this->gateway->findByOperation($uid,$key);
        if($entry===null)return null;
        $context=$this->authorize($uid,$entry['id']);
        if($context['sourceTool']!==$sourceTool)throw new ToolFailure('Operation not found.');
        return self::receipt($entry);
    }
    public function previewCancel(string $uid,array $args):array {
        $r=$this->read($uid,self::id($args))['delivery'];
        $p=['action'=>'cancel_delivery','delivery'=>$r,'notice'=>'Only a queued attempt can be cancelled. This cannot recall mail.'];
        return $p+['plan_state'=>$this->states->of('mail_cancel_delivery',$uid,$p)];
    }
    public function cancel(string $uid,array $args):array {
        PlanState::require($args);$p=BeforeEffectFailure::preflight(fn()=>$this->previewCancel($uid,$args));
        if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        $r=$p['delivery'];$this->journal->activeId($uid);$origin=$this->authorize($uid,$r['id']);
        $this->journal->checkpoint(['delivery_id'=>$r['id'],'account_id'=>$r['accountId'],'source_tool'=>$origin['sourceTool']]);
        return ['delivery'=>self::receipt($this->gateway->cancel($uid,$r['id'],$r['revision'])),'safe_to_retry'=>false];
    }
    public function previewRepair(string $uid,array $args):array {
        $id=self::id($args);$this->authorize($uid,$id);
        $p=['action'=>'repair_sent_copy','delivery'=>$this->gateway->previewSent($uid,$id),'notice'=>'Copies frozen accepted mail to the approved Sent folder. This action cannot send SMTP. An uncertain APPEND is not repeated.'];
        return $p+['plan_state'=>$this->states->of('mail_sent_repair',$uid,$p)];
    }
    public function repair(string $uid,array $args):array {
        PlanState::require($args);$p=BeforeEffectFailure::preflight(fn()=>$this->previewRepair($uid,$args));
        if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        $r=$p['delivery'];$id=self::id($args);$this->journal->activeId($uid);$origin=$this->authorize($uid,$id,true);
        $this->journal->checkpoint(['delivery_id'=>$id,'account_id'=>$r['accountId'],'source_tool'=>$origin['sourceTool']]);
        if($this->runner===null)throw new BeforeEffectFailure('The bounded Sent repair worker is unavailable.');
        $this->runner->run(['uid'=>$uid,'id'=>$id,'accountId'=>$origin['accountId'],'connectionId'=>$origin['connectionId'],'sourceTool'=>$origin['sourceTool']],'repair',$r['repairRevision']);
        return ['delivery'=>self::receipt($this->gateway->status($uid,$id)),'safe_to_retry'=>false];
    }

    public function authorize(string $uid,string $id,bool $repair=false):array {
        $context=$this->gateway->accessContext($uid,$id);
        if(($context['connectionId']??null)!==$this->connection->current()||!in_array($context['sourceTool']??null,['mail_send','mail_schedule'],true)
            ||!($this->allowsTool)($uid,$context['sourceTool'])||($repair&&!($this->allowsTool)($uid,'mail_sent_repair')))throw new ToolFailure('Delivery not found.');
        if($this->assertAccount!==null)($this->assertAccount)($uid,$context['accountId']);
        return $context;
    }
    private static function id(array $args):string {
        $id=$args['delivery_id']??null;if(!is_string($id)||strlen($id)<1||strlen($id)>128||preg_match('/^[A-Za-z0-9._-]+$/D',$id)!==1)throw new \InvalidArgumentException('Choose the delivery ID from its original operation.');return $id;
    }
    public static function receipt(array $r):array {return array_intersect_key($r,array_flip(['id','draftId','accountId','revision','state','sentState','sendAt','timezone','createdAt']));}
}
