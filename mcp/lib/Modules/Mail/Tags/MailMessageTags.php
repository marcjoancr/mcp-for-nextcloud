<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tags;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCA\Mcp\Modules\Mail\Integration\{MailTagTarget,MailMutationSession};
class MailMessageTags {
    public function __construct(private MailTagTarget $target,private PlanState $states,private MailMutationSession $session){}
    public function preview(string $uid,array $args):array {return $this->review($uid,$args)[0];}
    private function review(string $uid,array $args):array {
        foreach(['account_id','message_id','tag_id']as$key)if(!is_int($args[$key]??null)||$args[$key]<1)throw new \InvalidArgumentException('Choose one owned message and tag.');if(!is_bool($args['value']??null))throw new \InvalidArgumentException('Choose an explicit tag boolean.');
        $snapshot=$this->target->snapshot($uid,$args);$r=$snapshot['request'];$p=['action'=>'set_message_tag','account_id'=>$r['account_id'],'message_id'=>$r['message_id'],'mailbox_id'=>$r['mailbox_id'],'tag'=>$snapshot['tag'],'before'=>$snapshot['before'],'after'=>$r['value'],'untrusted_mail_data'=>true,'revision'=>$this->states->of('mail_tag_target',$uid,$r)];
        return [$p+['plan_state'=>$this->states->of('mail_set_message_tag',$uid,$p)],$r];
    }
    public function set(string $uid,array $args):array {PlanState::require($args);[$p,$r]=BeforeEffectFailure::preflight(fn():array=>$this->review($uid,$args));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);return $this->session->tag($uid,$r);}
}
