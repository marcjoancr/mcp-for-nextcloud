<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Sync;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCA\Mcp\Modules\Mail\Integration\{MailRefreshTarget,MailMutationSession};
use OCA\Mcp\Modules\Mail\Presentation\MailRows;
class MailRefresh {
    public function __construct(private MailRefreshTarget $target,private PlanState $states,private MailMutationSession $session){}
    public function preview(string $uid,array $args):array {return $this->review($uid,$args)[0];}
    private function review(string $uid,array $args):array {
        foreach(['account_id','mailbox_id']as$k)if(!is_int($args[$k]??null)||$args[$k]<1)throw new \InvalidArgumentException('Choose one owned mailbox.');if(isset($args['allow_cache_reset'])&&!is_bool($args['allow_cache_reset']))throw new \InvalidArgumentException('Choose an explicit cache reset boolean.');$s=$this->target->snapshot($uid,$args);$r=$s['request'];$p=['action'=>'refresh_mailbox','account_id'=>$r['account_id'],'mailbox_id'=>$r['mailbox_id'],'source'=>MailRows::text($r['mailbox']),'before'=>['cached_messages'=>$s['cached_messages'],'cache_reset_needed'=>$s['needs_reset']],'requested'=>['allow_cache_reset'=>$r['allow_cache_reset'],'maximum_messages'=>200],'message'=>$r['allow_cache_reset']?'An invalid cache or changed epoch will be replaced with new cached message IDs. Old IDs, cursors and reviewed plans will become invalid. Legacy tag history and remote messages are retained.':'A changed epoch or invalid cache will refuse before cache dispatch.','revision'=>$this->states->of('mail_refresh_target',$uid,$r)];return [$p+['plan_state'=>$this->states->of('mail_refresh_mailbox',$uid,$p)],$r];
    }
    public function refresh(string $uid,array $args):array {PlanState::require($args);[$p,$r]=BeforeEffectFailure::preflight(fn():array=>$this->review($uid,$args));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);return $this->session->refresh($uid,$r);}
}
