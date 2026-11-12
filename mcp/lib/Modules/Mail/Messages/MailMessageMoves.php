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
use OCA\Mcp\Modules\Mail\Mutations\MutationRequest;
class MailMessageMoves {
    public function __construct(private MailMutationTarget $target,private PlanState $states,private MailMutationSession $session){}
    public function preview(string $uid,array $args,string $action):array {return $this->review($uid,$args,$action)[0];}
    private function review(string $uid,array $args,string $action):array {
        if(!in_array($action,['move','archive','trash','restore'],true))throw new \InvalidArgumentException('Unsupported move action.');
        foreach(array_merge(['account_id','message_id'],in_array($action,['move','restore'],true)?['destination_mailbox_id']:[]) as $key)if(!is_int($args[$key]??null)||$args[$key]<1)throw new \InvalidArgumentException('Choose an owned message and an existing destination.');
        $r=$this->target->snapshot($uid,['action'=>$action]+$args)['request'];
        $p=['action'=>$action,'account_id'=>$r['account_id'],'message_id'=>$r['message_id'],'source'=>['id'=>$r['mailbox_id'],'name'=>$r['mailbox']],
            'destination'=>['id'=>$r['destination_mailbox_id'],'name'=>$r['destination_mailbox']],
            'revision'=>$this->states->of('mail_move_target',$uid,$r)];
        return [$p+['plan_state'=>$this->states->of(MutationRequest::tool($r),$uid,$p)],$r];
    }
    public function apply(string $uid,array $args,string $action):array {
        PlanState::require($args);[$p,$r]=BeforeEffectFailure::preflight(fn()=>$this->review($uid,$args,$action));
        if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        return $this->session->move($uid,$r);
    }
}
