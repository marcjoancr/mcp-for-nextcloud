<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tags;
use OCA\Mcp\Modules\Mail\Integration\MailTagGateway;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Operations\{OperationJournal,BeforeEffectFailure};
use OCA\Mcp\Db\MailProbeSlotsMapper;
use OCP\IDBConnection;
use OCP\AppFramework\Utility\ITimeFactory;
class MailTagCreation {
    public function __construct(private MailTagGateway $native,private PlanState $states,private OperationJournal $journal,private IDBConnection $db,private MailProbeSlotsMapper $slots,private ITimeFactory $clock,private \Closure $allows){}
    private function review(array $args,string $uid):array {
        if(!($this->allows)($uid,'mail_create_tag'))throw new BeforeEffectFailure('Creating Mail tags is not permitted.');
        $r=$this->native->prepare($uid,$args);$p=['action'=>'create_tag','scope'=>'all_owned_accounts','after'=>array_intersect_key($r,array_flip(['name','color']))];
        return [$p+['plan_state'=>$this->states->of('mail_create_tag',$uid,$r)],$r];
    }
    public function preview(array $args,string $uid):array{return $this->review($args,$uid)[0];}
    public function write(array $args,string $uid):array {
        PlanState::require($args);[$p,$r]=BeforeEffectFailure::preflight(fn():array=>$this->review($args,$uid));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        if(!$this->slots->reserve(hash('sha256',"tag-create\0".$uid),$this->journal->activeId($uid),$this->clock->getTime()))throw new BeforeEffectFailure('Mail tag creation rate limit reached.');
        return $this->journal->atomicLocal($this->db,function()use($args,$uid,$r):array{
            [$fresh,$current]=$this->review($args,$uid);if($current!==$r)throw new PlanChanged($fresh);
            $id=$this->native->insert($uid,$r);$this->journal->checkpoint(['tag_id'=>$id]);$actual=$this->native->read($uid,$id);
            foreach(['name','color','imap_label']as$key)if(($actual[$key]??null)!==$r[$key])throw new \RuntimeException('Native tag readback changed.');
            return ['tag'=>array_intersect_key($actual,array_flip(['tag_id','name','color']))];
        });
    }
}
