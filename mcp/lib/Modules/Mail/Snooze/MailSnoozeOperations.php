<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Snooze;
use OCA\Mcp\Modules\Mail\Integration\MailSnoozeGateway;
use OCA\Mcp\Modules\Mail\Transmission\ScheduleInstant;
use OCA\Mcp\Mcp\Operations\{OperationJournal,BeforeEffectFailure};
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\AppFramework\Utility\ITimeFactory;
class MailSnoozeOperations {
    public function __construct(private MailSnoozeGateway $gateway,private SnoozeRunner $runner,private OperationJournal $journal,private PlanState $states,private ConnectionContext $connection,private ITimeFactory $clock){}
    public function preview(string $uid,array $args):array {
        $at=ScheduleInstant::parse($args['wake_at'],$args['timezone'],$this->clock->getTime());$r=$this->gateway->preview($uid,$args['message_id'],$at,$args['timezone']);
        $p=['action'=>'snooze','message_id'=>$r['messageId'],'account_id'=>$r['accountId'],'source'=>$r['request']['mailbox'],'source_mailbox_id'=>$r['sourceMailboxId'],'snooze'=>$r['request']['destination_mailbox'],'snooze_mailbox_id'=>$r['snoozeMailboxId'],'wake_at'=>$at,'timezone'=>$r['timezone'],'revision'=>$r['revision'],'notice'=>'Move this one message to Snooze, then return it at the approved instant to this exact original folder. Uncertain moves never repeat automatically.'];return $p+['plan_state'=>$this->states->of('mail_snooze',$uid,$p)];
    }
    public function schedule(string $uid,array $args):array {
        PlanState::require($args);$p=BeforeEffectFailure::preflight(fn()=>$this->preview($uid,$args));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        $this->journal->checkpoint(['account_id'=>$p['account_id']]);$key=hash('sha256',$this->journal->scopeKey($uid)."\0".$this->journal->activeId($uid));
        $r=$this->gateway->schedule($uid,$p['message_id'],$p['wake_at'],$p['timezone'],$p['revision'],$key);$this->journal->checkpoint(['snooze_id'=>$r['id'],'account_id'=>$r['accountId'],'source_tool'=>'mail_snooze']);
        $this->runner->run($this->row($uid,$r['id']));return ['snooze'=>$this->gateway->status($uid,$r['id'],'mail_snooze'),'safe_to_retry'=>false,'notice'=>'The intent was admitted. Native placement and wake phases independently report movement evidence.'];
    }
    public function read(string $uid,string $id):array {return ['snooze'=>$this->gateway->status($uid,$id),'safe_to_retry'=>false];}
    public function recover(string $uid,string $operation):?array {return $this->gateway->recover($uid,hash('sha256',$this->journal->scopeKey($uid)."\0".$operation));}
    public function previewCancel(string $uid,array $args):array {$p=['action'=>'cancel_snooze','snooze'=>$this->gateway->status($uid,$args['snooze_id'],'mail_cancel_snooze'),'notice'=>'Cancel only the future wake. Leave the message in its currently proven folder.'];return $p+['plan_state'=>$this->states->of('mail_cancel_snooze',$uid,$p)];}
    public function cancel(string $uid,array $args):array {PlanState::require($args);$p=BeforeEffectFailure::preflight(fn()=>$this->previewCancel($uid,$args));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);$r=$p['snooze'];$this->journal->checkpoint(['snooze_id'=>$r['id'],'account_id'=>$r['accountId'],'source_tool'=>'mail_snooze']);return ['snooze'=>$this->gateway->cancel($uid,$r['id'],$r['revision']),'safe_to_retry'=>false];}
    public function previewWake(string $uid,array $args):array {$p=['action'=>'wake','snooze'=>$this->gateway->previewWake($uid,$args['snooze_id']),'notice'=>'Return this one message now to its exact original folder. This overrides only the approved wake time.'];return $p+['plan_state'=>$this->states->of('mail_wake',$uid,$p)];}
    public function wake(string $uid,array $args):array {
        PlanState::require($args);$p=BeforeEffectFailure::preflight(fn()=>$this->previewWake($uid,$args));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);$r=$p['snooze'];if($r['readiness']!=='ready')throw new BeforeEffectFailure('Waiting for native Mail to materialize the exact moved UID.');
        $this->journal->checkpoint(['snooze_id'=>$r['id'],'account_id'=>$r['accountId'],'source_tool'=>'mail_snooze','wake_revision'=>$r['wakeRevision']]);$this->runner->run($this->row($uid,$r['id']),'wake',$r['wakeRevision'],$this->journal->activeId($uid));return ['snooze'=>$this->gateway->status($uid,$r['id'],'mail_wake'),'safe_to_retry'=>false];
    }
    private function row(string $uid,string $id):array {$o=$this->gateway->origin($uid,$id);return ['uid'=>$uid,'id'=>$id,'accountId'=>$o['accountId'],'connectionId'=>$o['connectionId'],'sourceTool'=>'mail_snooze'];}
}
