<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Sieve;
use OCA\Mcp\Modules\Mail\Integration\MailSieveGateway;
use OCA\Mcp\Mcp\Operations\{OperationJournal,BeforeEffectFailure};
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
class MailSieveOperations {
    public function __construct(private MailSieveGateway $gateway,private OperationJournal $journal,private PlanState $states){}
    private function config(string $tool,array $args):array {return match($tool){'mail_write_filters','mail_transmit_filters'=>['filters'=>$args['filters']],'mail_write_absence','mail_enable_absence'=>['absence'=>$args['absence'],'follow_system'=>$args['follow_system']],default=>[]};}
    public function read(string $uid,string $tool,array $args):array {return $tool==='mail_read_sieve_status'?['sieve'=>$this->gateway->status($uid,$args['sieve_id'])]:$this->gateway->read($uid,$args['account_id'],$tool);}
    public function preview(string $uid,string $tool,array $args):array {$p=$this->gateway->preview($uid,$args['account_id'],$tool,$this->config($tool,$args));$p['action']=$tool;$p['notice']='Install one immutable candidate and observe activation. Original script retained. Native writers serialize; external clients can still race. Unknown acknowledgements never retry or roll back.';return $p+['plan_state'=>$this->states->of($tool,$uid,$p)];}
    public function apply(string $uid,string $tool,array $args):array {
        PlanState::require($args);$p=BeforeEffectFailure::preflight(fn()=>$this->preview($uid,$tool,$args));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        $this->journal->checkpoint(['account_id'=>$p['account_id'],'source_tool'=>$tool]);$key=hash('sha256',$this->journal->scopeKey($uid)."\0".$this->journal->activeId($uid));
        $r=$this->gateway->apply($uid,$p['account_id'],$tool,$this->config($tool,$args),$p['revision'],$key);$this->journal->checkpoint(['account_id'=>$p['account_id'],'sieve_id'=>$r['id'],'source_tool'=>$tool]);return ['sieve'=>$r,'safe_to_retry'=>false];
    }
    public function recover(string $uid,string $operation,string $tool):?array {return $this->gateway->recover($uid,hash('sha256',$this->journal->scopeKey($uid)."\0".$operation),$tool);}
}
