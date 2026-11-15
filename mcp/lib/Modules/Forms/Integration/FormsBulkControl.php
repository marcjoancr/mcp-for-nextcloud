<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Forms\Integration;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkAuthority;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkWorkflow;
use OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal;
/** Receives private journal context from trusted server code; a native cursor or key is never a tool argument. */
final class FormsBulkControl {
    private const CONTROLS=['forms_continue_operation','forms_cancel_operation'];
    public function snapshot(string $uid,string $tool,array $refs,array $origin,object $guard):array {
        if(!in_array($tool,[...self::CONTROLS,'forms_read_result'],true))throw new ToolFailure('Unsupported Forms bulk control.');
        $authority=$this->authority($refs);$id=$this->id($refs);
        $dto=$guard->bulkStatus($uid,$id,$origin);$outcome=$this->evidence($dto,$refs);
        if(in_array($tool,self::CONTROLS,true)&&(($dto['state']??null)!=='pending'||($dto['next']??null)!==true))
            throw new ToolFailure('This native Forms operation cannot accept another control; inspect its retained recovery evidence.');
        $status=$outcome['data'];
        return ['revision'=>'bulk-control:'.hash('sha256',json_encode(FormsOperationJournal::canonical([$tool,$dto['version'],$status,$outcome['references'],$outcome['phases']]),JSON_THROW_ON_ERROR)),
            'form'=>['id'=>$outcome['references']['form_id']??$refs['form_id']??null],
            'bulk_id'=>$id,'bulk_version'=>$dto['version'],'bulk_action'=>$authority['tool'],'bulk_authority'=>$authority,
            'bulk_origin'=>$origin,'bulk_status'=>$status,'impact'=>['bulk'=>$status+['recovery'=>$outcome['references']]]];
    }
    public function lookup(string $uid,string $key,string $tool,array $refs,array $origin,object $guard):?array {
        $authority=$this->authority($refs);
        if(in_array($tool,self::CONTROLS,true)) {
            $id=$this->id($refs);$version=$refs['bulk_version']??null;
            if(!is_int($version)||$version<1||!method_exists($guard,'bulkLookup')
                ||(new \ReflectionMethod($guard,'bulkLookup'))->getNumberOfParameters()<5)
                throw new ToolFailure('Qualified exact Forms step evidence is unavailable.');
            $dto=$guard->bulkLookup($uid,$key,$origin,$id,$version);
            // Only the qualified native five-argument fence can prove this exact step never began.
            if($dto===null)return ['state'=>'failed_before_effect','references'=>['bulk_id'=>$id,'bulk_action'=>$authority['tool']]];
            if(($dto['version']??null)!==$version+1)throw new ToolFailure('The native Forms receipt belongs to another approved step.');
            return $this->evidence($dto,$refs);
        }
        if($tool!==$authority['tool'])throw new ToolFailure('The original Forms bulk action is unavailable.');
        $dto=$guard->bulkLookup($uid,$key,$origin);
        // Missing initial evidence does not establish that a request could not still start.
        if($dto===null)return null;
        return $this->evidence($dto,$refs);
    }
    private function authority(array $refs):array {
        $authority=FormsBulkAuthority::checked($refs['bulk_authority']??null);
        if(isset($refs['bulk_action'])&&$refs['bulk_action']!==$authority['tool'])throw new ToolFailure('The original Forms bulk action changed.');
        return $authority;
    }
    private function id(array $refs):string {
        $id=$refs['bulk_id']??null;
        if(!is_string($id)||preg_match('/^[a-f0-9]{64}$/D',$id)!==1)throw new ToolFailure('The original Forms bulk identity is unavailable.');
        return $id;
    }
    private function evidence(array $dto,array $refs):array {
        $authority=$this->authority($refs);
        if(isset($refs['bulk_id'])&&($dto['bulk_id']??null)!==$this->id($refs))throw new ToolFailure('Native Forms evidence belongs to another operation.');
        if(isset($refs['form_id'])&&($dto['references']['form_id']??null)!==$refs['form_id'])throw new ToolFailure('Native Forms evidence belongs to another form.');
        return FormsBulkWorkflow::outcome($dto,$authority['tool']);
    }
}
