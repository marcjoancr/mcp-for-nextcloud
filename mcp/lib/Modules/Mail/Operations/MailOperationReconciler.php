<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Operations;
use OCA\Mcp\Db\{OperationsMapper,MailSetupWorkflowsMapper};
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Operations\{OperationJournal,BeforeEffectFailure};
use OCA\Mcp\Modules\Mail\Integration\MailSettingsGateway;
use OCA\Mcp\Modules\Mail\Mutations\MutationRequest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
/** Metadata only. An opaque identity, elapsed time or native absence is never completion proof. */
class MailOperationReconciler {
    public function __construct(private OperationsMapper $store,private MailSetupWorkflowsMapper $flows,private MailSettingsGateway $accounts,
        private OperationJournal $journal,private MailOperationReader $reader,private PlanState $states,private ITimeFactory $clock,private IDBConnection $db,private \OCA\Mcp\Modules\Mail\Integration\MailJournalStorage $storage){}
    public function preview(array $args,string $uid):array{return $this->review($args,$uid)[0];}
    private function review(array $args,string $uid):array {
        $this->storage->requireTables(['mcp_operations','mcp_mail_setup']);
        $scope=$this->journal->scopeKey($uid);$id=$args['target_operation_id'];
        $receipt=BeforeEffectFailure::preflight(fn():array=>$this->reader->get($uid,$id))['operation'];
        $row=$this->store->get($scope,$id);$account=(int)$args['account_id'];
        if($row===null||$row['actor_uid']!==$uid||($row['resource_refs']['account_id']??null)!==$account||!in_array($row['state'],['running','uncertain','repairing'],true))throw new BeforeEffectFailure('This operation has no eligible reconciliation state.');
        BeforeEffectFailure::preflight(fn():object=>$this->accounts->account($uid,$account));
        $refs=$row['resource_refs'];$next=$refs;$state='succeeded';$proof=null;$evidence=null;
        if(in_array($row['state'],['running','uncertain'],true)){
            $m=$refs['mutation']??[];
            if(($m['phase']??null)==='completed'&&MutationRequest::valid($m['descriptor']??[])&&MutationRequest::tool($m['descriptor'])===$row['tool_name']&&isset($m['remote'],$m['cache']))$proof='native_completed_receipt';
            if(($refs['local_completed']??false)===true&&in_array($row['tool_name'],['mail_add_alias','mail_update_alias','mail_delete_alias'],true)&&is_int($refs['alias_id']??null)&&$refs['alias_id']>0)$proof='atomic_local_completion';
            if(($refs['local_completed']??false)===true&&$row['tool_name']==='mail_reconcile_operation'&&is_string($refs['reconciled_operation_id']??null))$proof='atomic_reconciliation_completion';
            if($row['tool_name']==='mail_complete_account_setup'&&isset($refs['source_operation_id'])){
                $flow=$this->flows->get($scope,$refs['source_operation_id']);$source=$this->reader->get($uid,$refs['source_operation_id'])['operation'];
                if(($flow['phase']??null)==='completed'&&$flow['completion_id']===$id&&$flow['observed_account_id']===$account&&$source['state']==='succeeded'&&($source['resource_refs']['account_id']??null)===$account)$proof='completed_setup_binding';
            }
        } elseif(isset($args['evidence_operation_id'])&&in_array($row['tool_name'],['mail_write_draft','mail_attach_files'],true)){
            $evidenceReceipt=BeforeEffectFailure::preflight(fn():array=>$this->reader->get($uid,$args['evidence_operation_id']))['operation'];
            $evidence=$this->store->get($scope,$args['evidence_operation_id']);$e=$evidence['resource_refs']??[];
            if($evidence!==null&&$evidence['actor_uid']===$uid&&$evidence['state']==='succeeded'&&in_array($evidence['tool_name'],['mail_write_draft','mail_attach_files'],true)&&($e['repair_source_operation_id']??null)===$id&&($e['account_id']??null)===$account){
                $adopted=[];foreach($e['staged']??[] as $snapshot)foreach($refs['staged']??[] as $old)if(($old['attachment_id']??null)===($snapshot['attachment_id']??null)){
                    if($old!==$snapshot)throw new BeforeEffectFailure('Repair integrity evidence differs. Inspect native Mail.');$adopted[]=$old['attachment_id'];
                }
                $next['staged']=array_values(array_filter($refs['staged']??[],static fn(array $s):bool=>!in_array($s['attachment_id'],$adopted,true)));
                $proof='succeeded_repair_receipt';$state='repaired';
            }
        }
        if($proof===null)throw new BeforeEffectFailure('No exact durable completion proof is available. Preserve this claim and inspect native Mail with an administrator. Absence, age or acknowledgment cannot authorize a retry.');
        $tools=[$receipt['tool'],...($receipt['source_tools']??[])];if(isset($receipt['source_tool']))$tools[]=$receipt['source_tool'];
        if(isset($evidenceReceipt)){$tools[]=$evidenceReceipt['tool'];if(isset($evidenceReceipt['source_tool']))$tools[]=$evidenceReceipt['source_tool'];$tools=array_merge($tools,$evidenceReceipt['source_tools']??[]);}
        $tools=array_values(array_unique($tools));if(count($tools)>8)throw new BeforeEffectFailure('Source authorization chain exceeds the supported bound.');
        $p=['action'=>'reconcile_operation','account_id'=>$account,'target_operation_id'=>$id,'source_tool'=>$receipt['tool'],'before'=>['state'=>$receipt['state']],'after'=>['state'=>$state],'observed'=>['proof'=>$proof],'message'=>'Record the proven historical outcome only. This does not repeat a native write or prove the resource still exists.'];
        return [$p+['plan_state'=>$this->states->of('mail_reconcile_operation',$uid,[$p,$row,$evidence])],$row,$next,$state,$tools];
    }
    public function write(array $args,string $uid):array {
        PlanState::require($args);[$p]=$this->review($args,$uid);if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
        return $this->journal->atomicLocal($this->db,function()use($args,$uid):array{
            [$p,$row,$next,$state,$tools]=$this->review($args,$uid);if(!PlanState::matches($p['plan_state'],$args))throw new PlanChanged($p);
            if(!$this->store->reconcile($row,$state,$next,$this->clock->getTime()))throw new BeforeEffectFailure('The operation changed. Inspect it and review a fresh reconciliation.');
            $this->journal->checkpoint(['reconciled_operation_id'=>$args['target_operation_id'],'source_tool'=>$row['tool_name'],'source_tools'=>$tools]);
            return ['reconciled_operation_id'=>$args['target_operation_id'],'recorded_state'=>$state,'native_write_repeated'=>false];
        });
    }
}
