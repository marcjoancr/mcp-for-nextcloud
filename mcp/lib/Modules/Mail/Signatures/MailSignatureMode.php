<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Signatures;
use OCA\Mcp\Modules\Mail\Integration\{MailSignatureModeGateway,NativeDraftWrites};
use OCA\Mcp\Modules\Mail\Access\MailFreshAccess;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Operations\{OperationJournal,BeforeEffectFailure};
use OCP\IDBConnection;
class MailSignatureMode {
    public function __construct(private MailSignatureModeGateway $gateway,private PlanState $states,private OperationJournal $journal,private IDBConnection $db,private NativeDraftWrites $writes,private MailFreshAccess $fresh){}
    public function preview(array $args,string $uid):array {
        if(!is_int($args['signature_mode']??null)||!in_array($args['signature_mode'],[0,1],true))throw new \InvalidArgumentException('Choose native mode 0 (plain text) or 1 (HTML).');
        $before=$this->gateway->read($uid,$args['account_id'],$args['alias_id']??null);$p=['action'=>'write_signature_mode','account_id'=>$args['account_id'],'alias_id'=>$args['alias_id']??null,'before'=>$before,'after'=>['signature_mode'=>$args['signature_mode']]];
        return $p+['plan_state'=>$this->states->of('mail_write_signature_mode',$uid,$p)];
    }
    public function write(array $args,string $uid):array {
        PlanState::require($args);$p=BeforeEffectFailure::preflight(fn()=>$this->preview($args,$uid));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        $guard=$this->fresh->activeFence($uid,'mail_write_signature_mode');
        return $this->writes->run($uid,fn():array=>$this->journal->atomicLocal($this->db,function()use($args,$uid,$guard):array{
            $guard();$p=$this->preview($args,$uid);if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
            $r=$this->gateway->write($uid,$args['account_id'],$args['alias_id']??null,$args['signature_mode'],$p['before']['revision'],$guard);
            $guard();$this->journal->checkpoint(['account_id'=>$args['account_id'],'alias_id'=>$args['alias_id']??null,'signature_mode'=>$args['signature_mode']]);return ['identity'=>$r];
        },function()use($uid,$guard):void{$guard();$this->gateway->assertOwner($uid);}));
    }
}
