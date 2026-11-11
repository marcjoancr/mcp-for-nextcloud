<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Accounts;
use OCA\Mcp\Modules\Mail\Integration\{MailSettingsGateway,MailServices};
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Db\{OperationsMapper,MailSetupWorkflowsMapper};
use OCP\AppFramework\Utility\ITimeFactory;
class MailAccountRemoval {
    public function __construct(private MailSettingsGateway $gateway,private MailServices $native,private PlanState $states,
        private OperationsMapper $operations,private MailSetupWorkflowsMapper $workflows,private ITimeFactory $clock,private ?\OCA\Mcp\Modules\Mail\Integration\MailDeliveryGateway $delivery=null){}
    public function preview(array $args,string $uid): array {
        $id=(int)$args['account_id'];$profile=$this->gateway->readConnection($uid,$id);
        if(!$profile['editable'])throw new BeforeEffectFailure('Provisioned accounts must be managed by their administrator.');
        if(($this->delivery?->hasUnresolved($uid,$id)??false)||$this->operations->unresolvedAccount($uid,$id,$args['operation_id']??'')||$this->workflows->pendingForAccount($id,$this->clock->getTime()))throw new BeforeEffectFailure('Inspect pending or uncertain operations before removing this account.');
        $mapper=$this->native->localMessageMapper($uid);$counts=[];
        foreach(['local_drafts'=>$this->native->localDraftType($uid),'outgoing_items'=>$this->native->localOutgoingType($uid)] as $key=>$type){
            $counts[$key]=count(array_filter($mapper->getAllForUser($uid,$type),static fn(object $m):bool=>(int)$m->getAccountId()===$id));
        }
        if(array_sum($counts)>0)throw new BeforeEffectFailure('This account has local drafts or outgoing items. Review them in native Mail before removing the account.');
        $account=$this->gateway->account($uid,$id);$mailboxes=$this->native->mailboxMapper($uid)->findAll($account);
        $aliases=$this->native->aliasesService($uid)->findAll($id,$uid);
        $impact=$counts+['aliases_removed'=>count($aliases),'cached_mailboxes'=>count($mailboxes),'remote_messages_deleted'=>false,'files_deleted'=>false,
            'cache_and_workers'=>'Account access is removed; native Mail controls local cache and background work.'];
        $p=['action'=>'delete_account','account_id'=>$id,'account'=>['name'=>$profile['name'],'email'=>$profile['email']],'impact'=>$impact];
        return $p+['plan_state'=>$this->states->of('mail_delete_account',$uid,$p+['revision'=>$profile['revision']])];
    }
    public function write(array $args,string $uid): array {
        PlanState::require($args);$p=\OCA\Mcp\Mcp\Operations\BeforeEffectFailure::preflight(fn()=>$this->preview($args,$uid));if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        $id=(int)$args['account_id'];$this->native->accountService($uid)->delete($uid,$id);
        try{$this->gateway->account($uid,$id);}catch(ToolFailure){return ['account_id'=>$id,'removed'=>true];}
        throw new ToolFailure('Native account removal could not be verified; inspect its operation.');
    }
}
