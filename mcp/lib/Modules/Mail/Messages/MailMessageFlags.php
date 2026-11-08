<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Messages;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCA\Mcp\Modules\Mail\Integration\{MailMutationTarget,MailMutationSession};
class MailMessageFlags {
    public function __construct(private MailMutationTarget $target,private PlanState $states,private MailMutationSession $session){}
    public function preview(string $uid,array $args):array {
        if(!is_int($args['account_id']??null)||$args['account_id']<1||!is_int($args['message_id']??null)||$args['message_id']<1||!in_array($args['flag']??null,['seen','flagged'],true)||!is_bool($args['value']??null))throw new \InvalidArgumentException('Choose one owned message, seen or flagged, and an explicit boolean.');
        $snapshot=$this->target->snapshot($uid,$args);$r=$snapshot['request'];
        $p=['action'=>'set_message_flag','account_id'=>$r['account_id'],'message_id'=>$r['message_id'],'mailbox_id'=>$r['mailbox_id'],'flag'=>$r['flag'],
            'before'=>$snapshot['before'],'after'=>$r['value'],'revision'=>$this->states->of('mail_flag_target',$uid,$r)];
        return $p+['plan_state'=>$this->states->of('mail_set_message_flag',$uid,$p)];
    }
    public function set(string $uid,array $args):array {
        PlanState::require($args);$p=BeforeEffectFailure::preflight(fn()=>$this->preview($uid,$args));
        if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        return $this->session->setFlag($uid,$this->target->snapshot($uid,$args)['request']);
    }
}
