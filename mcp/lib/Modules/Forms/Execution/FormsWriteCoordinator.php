<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Execution;
use OCA\Mcp\Modules\Forms\Integration\FormsGateway;
use OCA\Mcp\Modules\Forms\Presentation\FormsProjection;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** One confirmation boundary for every extended Forms command. */
final class FormsWriteCoordinator {
    /** @param \Closure(string,string,array,array):void $authorize */
    public function __construct(private FormsGateway $native,private PlanState $proof,private FormsOperationJournal $journal,private \Closure $authorize) {}
    public function capabilities(string $uid):array {return $this->native->capabilities($uid);}
    public function call(string $tool,string $uid,array $arguments):array {
        $args=$this->arguments($tool,$arguments);($this->authorize)($uid,$tool,$args,[]);
        if($tool==='forms_capabilities')return $this->native->capabilities($uid);
        if($tool==='forms_get_operation')return $this->journal->get($uid,$args['operation_id'],$this->historical($uid),fn(string $key,string $tool,array $refs):?array=>$this->native->lookup($uid,$key,['tool'=>$tool,'references'=>$refs]));
        if($tool==='forms_list_operations')return $this->journal->list($uid,$args['cursor']??null,$args['limit']??50,$this->historical($uid));
        if(FormsCommands::readOnly($tool)) {
            $actor=$this->journal->actor($uid);$snapshot=$this->native->snapshot($uid,$tool,$args);
            ($this->authorize)($uid,$tool,$args,$snapshot);
            $raw=$this->native->read($uid,$tool,$args);
            $after=$this->native->snapshot($uid,$tool,$args);
            if(($snapshot['revision']??null)!==($after['revision']??null))throw new ToolFailure('Forms changed during the read; request the page again.');
            $this->journal->assertCurrent($uid,$actor);($this->authorize)($uid,$tool,$args,$after);
            return FormsProjection::read($tool,$raw,$args);
        }
        if(($args['confirm']??false)!==true)return $this->preview($tool,$uid,$args);
        PlanState::require($args);
        $result=$this->journal->execute($tool,$uid,$args,function(string $nativeKey,\Closure $checkpoint)use($tool,$uid,$args):array {
            try {
            $snapshot=$this->native->snapshot($uid,$tool,$this->effect($args));
            ($this->authorize)($uid,$tool,$args,$snapshot);
            $plan=FormsPlan::build($tool,$args,$snapshot,$uid);
            if(!PlanState::matches($this->state($tool,$uid,$args,$snapshot),$args))throw new PlanChanged($this->preview($tool,$uid,$args));
            if($plan['requiresSharedConfirmation']&&!($args['confirm_shared']??false))throw new FormsBeforeEffect('Confirm the shared-resource effects before writing.');
            $admission=['required_operations'=>\OCA\Mcp\Modules\Forms\Access\FormsAuthorization::operations($tool,$args,$snapshot),
                'files_operations'=>\OCA\Mcp\Modules\Forms\Access\FormsAuthorization::fileOperations($tool,$args,$snapshot),
                'requires_admin'=>FormsCommands::definitions()[$tool]['requiresAdmin']??false,'job_version'=>$snapshot['job_version']??null];
            $checkpoint($admission+array_intersect_key($snapshot,array_flip(['bulk_authority','bulk_version'])));
            } catch(PlanChanged|FormsBeforeEffect $failure) {throw $failure;}
            catch(\Throwable) {throw new FormsBeforeEffect('Forms preflight could not be completed. No native change was started; review a new preview.');}
            return $this->native->apply($uid,$tool,$this->effect($args),(string)$snapshot['revision'],$nativeKey);
        },$this->historical($uid),in_array($tool,['forms_continue_operation','forms_cancel_operation'],true)
            ?fn():array=>array_intersect_key($this->native->snapshot($uid,$tool,$this->effect($args)),array_flip(['job_version','bulk_id','bulk_version'])):null);
        ($this->authorize)($uid,$tool,$args,[]);
        if(array_key_exists('data',$result))$result['data']=FormsProjection::mutation($result['data'],$tool);
        return FormsProjection::bounded($result);
    }
    public function preview(string $tool,string $uid,array $arguments):array {
        $args=$this->arguments($tool,$arguments);
        ($this->authorize)($uid,$tool,$args,[]);
        $snapshot=$this->native->snapshot($uid,$tool,$this->effect($args));
        ($this->authorize)($uid,$tool,$args,$snapshot);
        $plan=FormsPlan::build($tool,$args,$snapshot,$uid);
        $plan['plan_state']=$this->state($tool,$uid,$args,$snapshot);
        return $this->journal->plan($tool,$uid,$args,$plan);
    }
    private function state(string $tool,string $uid,array $args,array $snapshot):string {
        return $this->proof->of($tool,$uid,FormsOperationJournal::canonical([
            'actor'=>$this->journal->actor($uid),'connection'=>$this->journal->connection(),
            'arguments'=>$this->effect($args),'snapshot'=>$snapshot]));
    }
    private function effect(array $args):array {
        unset($args['confirm'],$args['confirm_shared'],$args['plan_state'],$args['operation_id']);
        return $args;
    }
    private function arguments(string $tool,array $args):array {
        $definition=FormsCommands::definitions()[$tool]??throw new \InvalidArgumentException('Unknown Forms command');
        if(strlen(json_encode($args,JSON_THROW_ON_ERROR))>1048576)throw new ToolFailure('Forms arguments exceed the byte limit.');
        $validated=ArgumentValidator::validate(WriteGate::publish($definition)['inputSchema'],$args);
        if($tool==='forms_create_question'&&($validated['type']??null)==='grid'&&!isset($validated['subtype']))throw new \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException('Invalid argument: subtype','subtype','Choose radio, checkbox or number when creating a grid question.');
        return $validated;
    }
    private function historical(string $uid):\Closure {
        return function(string $tool,array $refs)use($uid):void {
            ($this->authorize)($uid,$tool,['id'=>$refs['form_id']??null],['historical'=>$refs]);
        };
    }
}
