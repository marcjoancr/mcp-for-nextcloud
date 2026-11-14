<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Execution;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Operations\OperationStore;
use OCA\Mcp\Mcp\Operations\ActorIdentity;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\AppFramework\Utility\ITimeFactory;

/** Forms has independent signatures and receipts; native evidence is never permission to repeat an effect. */
final class FormsOperationJournal {
    private const REFERENCES = ['form_id','question_id','option_id','submission_id','share_id','comment_id','file_id','job_id','backup_id','native_operation','bulk_id','bulk_action','backup_format','evidence_id','recovery_scope','restored_form_id','database','files','notifications','linked_export'];
    public function __construct(private OperationStore $store, private PlanState $proof, private ConnectionContext $connection,
        private ITimeFactory $clock, private ActorIdentity $actors,private ?FormsOutcomeStore $outcomes=null) {}

    public function plan(string $tool,string $uid,array $arguments,array $plan):array {
        $actor=$this->actors->capture($uid);
        $base=bin2hex(random_bytes(16)).'.'.($this->clock->getTime()+900);
        $args=array_replace($arguments,['plan_state'=>$plan['plan_state']]);
        $signature=$this->signature($tool,$uid,$actor,$base,$this->effect($args));
        return $plan+['operation_id'=>$base.'.'.$signature,'operation_expires_at'=>$this->clock->getTime()+900];
    }

    public function execute(string $tool,string $uid,array $arguments,\Closure $effect,?\Closure $authorizeReplay=null,?\Closure $prepareReservation=null):array {
        $id=$arguments['operation_id']??null;
        if(!is_string($id)||preg_match('/^([a-f0-9]{32}\.([0-9]{1,12}))\.([a-f0-9]{64})$/D',$id,$parts)!==1) {
            throw new ToolFailure('Repeat the operation_id from the approved Forms preview.');
        }
        $actor=$this->actors->capture($uid);$digest=$this->effect($arguments);
        if(!hash_equals($this->signature($tool,$uid,$actor,$parts[1],$digest),$parts[3])) {
            throw new ToolFailure('Forms operation does not match the approved actor, connection or request.');
        }
        $scope=$this->scope($uid);$old=$this->store->get($scope,$id);
        if($old!==null) {
            $this->assertActor($uid,$old);
            $authorizeReplay?->__invoke($old['tool_name'],$old['resource_refs']);
            return ['operation'=>$this->present($old,true)];
        }
        if((int)$parts[2]<$this->clock->getTime())throw new ToolFailure('Forms operation plan expired; preview again.');
        $nativeKey=$this->proof->of('forms_native_operation_v1',$uid,[$scope,$id,$digest]);
        $refs=['actor_incarnation'=>$actor,'connection_id'=>$this->connection->current(),'native_key'=>$nativeKey];
        if(isset($arguments['id']))$refs['form_id']=$arguments['id'];
        if(isset($arguments['comment_id']))$refs['comment_id']=$arguments['comment_id'];
        $target=isset($arguments['id'])?'form:'.$arguments['id']:'create:'.$uid;
        if($tool==='forms_generate_link_token')$target='candidate-token:'.$uid.':'.$id;
        if(in_array($tool,['forms_continue_operation','forms_cancel_operation'],true)) {
            $original=$this->store->get($scope,$arguments['target_operation_id']??'');
            if($original===null)throw new ToolFailure(ToolFailure::NOT_FOUND);
            $this->assertActor($uid,$original);$authorizeReplay?->__invoke($original['tool_name'],$original['resource_refs']);
            $prepared=$prepareReservation?->__invoke()??[];
            if(isset($original['resource_refs']['bulk_id'])) {
                if(in_array($original['state'],['running','uncertain'],true))throw new ToolFailure('The original Forms bulk result is unresolved; inspect its evidence before any control.');
                $bulk=$original['resource_refs']['bulk_id'];
                if(!is_string($bulk)||preg_match('/^[a-f0-9]{64}$/D',$bulk)!==1
                    ||($prepared['bulk_id']??null)!==$bulk||!is_int($prepared['bulk_version']??null)||$prepared['bulk_version']<1
                    ||isset($prepared['job_version'])||isset($original['resource_refs']['job_id']))
                    throw new ToolFailure('A current matching Forms bulk version is required before reservation.');
                $authority=FormsBulkAuthority::checked($original['resource_refs']['bulk_authority']??null);
                if(($original['resource_refs']['bulk_action']??null)!==$authority['tool'])
                    throw new ToolFailure('The original Forms bulk action is unavailable.');
                $refs+=['bulk_id'=>$bulk,'bulk_action'=>$authority['tool'],'bulk_version'=>$prepared['bulk_version'],'bulk_authority'=>$authority];
                $target='bulk:'.$bulk;
            } else {
                $job=$original['resource_refs']['job_id']??throw new ToolFailure('The original operation has no Forms job.');
                $refs['job_id']=$job;
                if(!is_int($prepared['job_version']??null)||$prepared['job_version']<1)throw new ToolFailure('A current Forms job version is required before reservation.');
                $refs['job_version']=$prepared['job_version'];
                $target='job:'.$job;
            }
            if(isset($original['resource_refs']['form_id']))$refs['form_id']=$original['resource_refs']['form_id'];
        }
        $row=['scope_key'=>$scope,'actor_uid'=>$uid,'operation_id'=>$id,'tool_name'=>$tool,'effect_digest'=>$digest,
            'target_key'=>hash('sha256','forms-target-v1:'.$target),'state'=>'running','resource_refs'=>$refs,
            'created_at'=>$this->clock->getTime(),'updated_at'=>$this->clock->getTime()];
        if(!$this->store->reserve($row))throw new ToolFailure('Another Forms operation owns this target; inspect its outcome.');
        try {
            $this->actors->assertCurrent($uid,$actor);
            $checkpoint=function(array $authority)use($scope,$id,$tool,&$refs):void {
                $authority=array_intersect_key($authority,array_flip(['required_operations','files_operations','requires_admin','job_version','bulk_version','bulk_authority']));
                if(isset($refs['job_version'])&&($authority['job_version']??null)!==$refs['job_version'])throw new FormsBeforeEffect('The Forms job changed during admission.');
                if(isset($refs['bulk_version'])&&($authority['bulk_version']??null)!==$refs['bulk_version'])throw new FormsBeforeEffect('The Forms bulk step changed during admission.');
                if(array_key_exists('bulk_authority',$authority)) {
                    $authority['bulk_authority']=FormsBulkAuthority::checked($authority['bulk_authority']);
                    if(isset($refs['bulk_authority'])&&FormsOperationJournal::canonical($refs['bulk_authority'])!==FormsOperationJournal::canonical($authority['bulk_authority']))
                        throw new FormsBeforeEffect('The original Forms bulk authority changed during admission.');
                    if(!isset($refs['bulk_authority'])&&$authority['bulk_authority']['tool']!==$tool)
                        throw new FormsBeforeEffect('The original Forms bulk action changed during admission.');
                }
                $refs=array_replace($refs,$authority);$this->store->checkpoint($scope,$id,$refs);
            };
            $native=$effect($nativeKey,$checkpoint);
            $this->actors->assertCurrent($uid,$actor);
            if(!is_array($native))throw new \UnexpectedValueException('Missing native outcome');
            $state=$native['state']??'uncertain';
            if(!in_array($state,['succeeded','partial','uncertain','failed_before_effect','pending'],true))$state='uncertain';
            $evidence=array_intersect_key($native['references']??[],array_flip(self::REFERENCES));
            $refs=array_replace($refs,$evidence);
        } catch(PlanChanged|FormsBeforeEffect $failure) {
            $this->store->finish($scope,$id,'failed_before_effect',$refs,$this->clock->getTime());
            throw $failure;
        } catch(\Throwable) {
            $state='uncertain';$native=[];
        }
        // A missing local acknowledgement never permits the callback to execute again.
        $this->store->finish($scope,$id,$state,$refs,$this->clock->getTime());
        $row['state']=$state;$row['resource_refs']=$refs;$row['updated_at']=$this->clock->getTime();
        return ['data'=>$native['data']??null,'operation'=>$this->present($row,false)];
    }

    /** Internal lookup only. Private admission and operation keys never enter public history. */
    public function context(string $uid,string $id,\Closure $authorize):array {
        $row=$this->store->get($this->scope($uid),$id);
        if($row===null)throw new ToolFailure(ToolFailure::NOT_FOUND);
        $this->assertActor($uid,$row);$authorize($row['tool_name'],$row['resource_refs']);$this->assertActor($uid,$row);
        return ['tool'=>$row['tool_name'],'references'=>$row['resource_refs']];
    }

    /** @param \Closure(string,array):void $authorize */
    public function get(string $uid,string $id,\Closure $authorize,?\Closure $lookup=null):array {
        $row=$this->store->get($this->scope($uid),$id);
        if($row===null)throw new ToolFailure(ToolFailure::NOT_FOUND);
        $this->assertActor($uid,$row);$authorize($row['tool_name'],$row['resource_refs']);
        if($lookup!==null&&$this->outcomes!==null&&in_array($row['state'],['running','uncertain','partial'],true)&&isset($row['resource_refs']['native_key'])) {
            try {
                $native=$lookup($row['resource_refs']['native_key'],$row['tool_name'],$row['resource_refs']);
                $accepted=$row['state']==='partial'?['succeeded','partial']:['succeeded','partial','pending','failed_before_effect','uncertain'];
                if(is_array($native)&&in_array($native['state']??null,$accepted,true)) {
                    $this->assertActor($uid,$row);$authorize($row['tool_name'],$row['resource_refs']);
                    $refs=array_replace($row['resource_refs'],array_intersect_key($native['references']??[],array_flip(self::REFERENCES)));
                    $adopted=$native['state']==='uncertain'
                        ?$this->outcomes->retainUncertain($row,$refs,$this->clock->getTime())
                        :$this->outcomes->reconcile($row,$native['state'],$refs,$this->clock->getTime());
                    if($adopted)$row=$this->store->get($this->scope($uid),$id)??$row;
                }
            } catch(\Throwable) { /* An unavailable native receipt remains uncertain; no callback is repeated. */ }
        }
        $this->assertActor($uid,$row);$authorize($row['tool_name'],$row['resource_refs']);
        return $this->present($row,false);
    }

    public function list(string $uid,?string $cursor,int $limit,\Closure $authorize):array {
        $offset=$cursor===null?0:$this->offset($uid,$cursor);
        $limit=min(100,max(1,$limit));$items=[];
        $rows=$this->store->list($this->scope($uid),$limit+1,$offset);
        $more=count($rows)>$limit;
        foreach(array_slice($rows,0,$limit) as $row) {
            $this->assertActor($uid,$row);
            try{$authorize($row['tool_name'],$row['resource_refs']);}catch(ToolFailure){continue;}
            $items[]=$this->present($row,false);
        }
        $next=$offset+$limit;
        return ['operations'=>$items,'hasMore'=>$more,'nextCursor'=>$more?$next.'.'.$this->proof->of('forms_cursor_v1',$uid,[$this->connection->current(),$next]):null];
    }

    public function actor(string $uid):string {return $this->actors->capture($uid);}
    public function assertCurrent(string $uid,string $actor):void {$this->actors->assertCurrent($uid,$actor);}
    public function connection():int {return $this->connection->current();}

    private function scope(string $uid):string {return $this->proof->of('forms_scope_v1',$uid,[$this->connection->current()]);}
    private function signature(string $tool,string $uid,string $actor,string $base,string $digest):string {
        return $this->proof->of('forms_operation_v1',$uid,[$this->connection->current(),$actor,$tool,$base,$digest]);
    }
    private function effect(array $args):string {
        unset($args['confirm'],$args['confirm_shared'],$args['operation_id']);
        return $this->proof->of('forms_effect_v1','',self::canonical($args));
    }
    public static function canonical(array $data):array {
        if(!array_is_list($data))ksort($data);
        foreach($data as &$value)if(is_array($value))$value=self::canonical($value);
        return $data;
    }
    private function assertActor(string $uid,array $row):void {
        if(!str_starts_with($row['tool_name']??'','forms_')||!is_string($row['resource_refs']['actor_incarnation']??null))throw new ToolFailure(ToolFailure::NOT_FOUND);
        $this->actors->assertCurrent($uid,$row['resource_refs']['actor_incarnation']);
    }
    private function present(array $row,bool $replayed):array {
        return ['operation_id'=>$row['operation_id'],'tool'=>$row['tool_name'],'state'=>$row['state'],
            'references'=>array_intersect_key($row['resource_refs'],array_flip(self::REFERENCES)),
            'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at'],'replayed'=>$replayed,'safe_to_retry'=>false];
    }
    private function offset(string $uid,string $cursor):int {
        if(preg_match('/^(0|[1-9][0-9]{0,8})\.([a-f0-9]{64})$/D',$cursor,$m)!==1)throw new ToolFailure('Invalid Forms cursor.');
        $offset=(int)$m[1];
        if(!hash_equals($this->proof->of('forms_cursor_v1',$uid,[$this->connection->current(),$offset]),$m[2]))throw new ToolFailure('Invalid Forms cursor.');
        return $offset;
    }
}
