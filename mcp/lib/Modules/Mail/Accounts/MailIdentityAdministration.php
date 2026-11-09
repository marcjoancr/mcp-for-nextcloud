<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Accounts;
use OCA\Mcp\Modules\Mail\Integration\MailAliasGateway;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Operations\{OperationJournal,BeforeEffectFailure};
use OCP\IDBConnection;
class MailIdentityAdministration {
    public function __construct(private MailAliasGateway $gateway,private PlanState $states,private OperationJournal $journal,private IDBConnection $db,private \Closure $allows){}
    public function preview(array $args,string $uid,string $action):array {
        if(!($this->allows)($uid,'mail_'.$action.'_alias'))throw new BeforeEffectFailure('This identity action is not permitted.');
        $r=$this->gateway->prepare($uid,$args,$action);$p=['action'=>$action.'_alias']+$r;
        return $p+['plan_state'=>$this->states->of('mail_'.$action.'_alias',$uid,$p)];
    }
    public function write(array $args,string $uid,string $action):array {
        PlanState::require($args);$p=BeforeEffectFailure::preflight(fn():array=>$this->preview($args,$uid,$action));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        return $this->journal->atomicLocal($this->db,function()use($args,$uid,$action):array{
            $p=$this->preview($args,$uid,$action);if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
            $id=$this->gateway->apply($uid,$args,$action);if($id<1)throw new \RuntimeException('Native identity checkpoint is unavailable.');
            $this->journal->checkpoint(['alias_id'=>$id]);
            if($action==='delete')return ['account_id'=>(int)$args['account_id'],'deleted_alias_id'=>$id];
            $identity=$this->gateway->read($uid,(int)$args['account_id'],$id);
            foreach(['name','email'] as $key)if($identity[$key]!==$p['after'][$key])throw new \RuntimeException('Native identity readback differs.');
            unset($identity['revision']);return ['identity'=>$identity];
        });
    }
}
