<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Mailboxes;
use OCA\Mcp\Modules\Mail\Integration\{MailMutationTarget,MailMutationSession};
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCA\Mcp\Modules\Mail\Mutations\MutationRequest;
class MailboxMutations {
    public function __construct(private MailMutationTarget $target,private MailMutationSession $session,private PlanState $states){}
    public function preview(array $args,string $uid,string $action):array {return $this->review($args,$uid,$action)[0];}
    private function review(array $args,string $uid,string $action):array {
        $r=$this->target->snapshot($uid,['action'=>$action]+$args)['request'];$p=['action'=>$action,'account_id'=>$r['account_id'],'mailbox_id'=>$r['mailbox_id'],'before'=>['name'=>$r['name'],'folders'=>array_map(static fn(array $b):array=>['id'=>$b['id'],'name'=>$b['name'],'subscribed'=>$b['subscribed']],$r['tree'])],
            'after'=>['name'=>$r['new_name'],'subscribed'=>$r['subscribed']],'revision'=>$this->states->of('mail_folder_target',$uid,$r)];
        return [$p+['plan_state'=>$this->states->of(MutationRequest::tool($r),$uid,$p)],$r];
    }
    public function write(array $args,string $uid,string $action):array {
        PlanState::require($args);[$p,$r]=BeforeEffectFailure::preflight(fn()=>$this->review($args,$uid,$action));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);return $this->session->mailbox($uid,$r);
    }
}
