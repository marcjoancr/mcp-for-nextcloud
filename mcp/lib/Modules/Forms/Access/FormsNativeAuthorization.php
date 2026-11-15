<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Access;
use OCA\Mcp\OAuth\ConnectionContext;
/** Entry called by the qualified native companion; native ACL and exact event-data checks remain native responsibilities. */
final class FormsNativeAuthorization {
    public function __construct(private FormsOriginProof $proof,private FormsFreshAccess $fresh,private FormsDeferredAccess $deferred,private ConnectionContext $connection,
        private \OCA\Mcp\Access\Grants\GrantPolicy $policy,private \OCA\Mcp\Modules\Forms\Attachments\FormsFileImpact $impact) {}
    public function capture(string $uid,string $tool,array $args,array $snapshot):array {
        $this->fresh->assertTool($uid,$tool,$args,$snapshot);$references=[];
        if(isset($args['id']))$references['form_id']=$args['id'];
        if(isset($args['submission_id']))$references['submission_id']=$args['submission_id'];
        if(isset($args['share_id']))$references['share_id']=$args['share_id'];
        if(isset($args['comment_id']))$references['comment_id']=$args['comment_id'];
        if(isset($snapshot['form']['ownerId']))$references['owner_id']=$snapshot['form']['ownerId'];
        $files=FormsAuthorization::fileOperations($tool,$args,$snapshot);
        if($files!==[])$references['files_operations']=array_values(array_unique($files));
        if(isset($snapshot['bulk_authority']))$references['bulk_authority']=\OCA\Mcp\Modules\Forms\Execution\FormsBulkAuthority::checked($snapshot['bulk_authority']);
        return $this->proof->create($uid,$this->connection->current(),$tool,FormsAuthorization::operations($tool,$args,$snapshot),$references);
    }
    public function captureHistory(string $uid,string $tool,array $references):array {
        $this->assertHistory($uid,$tool,$references);
        $refs=array_intersect_key($references,array_flip(['form_id','submission_id','share_id','comment_id','files_operations','bulk_authority']));
        return $this->proof->create($uid,$this->connection->current(),$tool,$references['required_operations']??FormsAuthorization::operations($tool,['id'=>$refs['form_id']??null],[]),$refs);
    }
    public function assertHistory(string $uid,string $tool,array $references):void {
        $this->fresh->assertTool($uid,$tool,['id'=>$references['form_id']??null],['historical'=>$references]);
    }
    /** Server-only callback for actual user Files destinations, independent of the source owner's tree. */
    public function assertDestination(array $origin,\OCP\Files\Folder $folder,bool $create):void {
        $context=$this->assert($origin);
        if(!in_array($context['tool'],['forms_restore','forms_transfer'],true))
            throw new \RuntimeException('Forms destination authority is unavailable.');
        $this->policy->resourceBoundary($context['uid'],fn()=>$this->impact->assertDestination($folder,$create));
    }
    public function assertFiles(array $origin,string $action,array $manifest):void {
        $context=$this->assert($origin);
        if(($manifest['source_files']??[])!==[])$this->policy->resourceBoundary($context['uid'],fn()=>$this->impact->assertVisible($context['uid'],['files'=>$manifest['source_files']]));
        if(in_array($action,['forms_submit_response','forms_update_response','forms_update_own_response'],true))return;
        if(($manifest['files']??[])===[]&&!isset($manifest['share_impact']['parent'])&&($manifest['share_removals']??[])===[])return;
        $this->policy->resourceBoundary($context['uid'],fn()=>$this->impact->assertVisible($context['uid'],$manifest));
    }
    /** Validate original and immediate bulk-control authority in one new policy window, never a cached admission. */
    public function assertPair(array $origin,array $control):array {
        $original=$this->proof->verify($origin);$current=$this->proof->verify($control);
        foreach(['uid','actor','connection'] as $key)if($original[$key]!==$current[$key])throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::FORBIDDEN);
        $authority=isset($current['references']['bulk_authority'])?\OCA\Mcp\Modules\Forms\Execution\FormsBulkAuthority::checked($current['references']['bulk_authority']):null;
        if(!in_array($current['tool'],['forms_continue_operation','forms_cancel_operation'],true)||$authority===null
            ||$authority['tool']!==$original['tool']||$authority['required_operations']!==$original['operations']
            ||$authority['files_operations']!==($original['references']['files_operations']??[])
            ||(isset($current['references']['form_id'])&&$current['references']['form_id']!==($original['references']['form_id']??null)))
            throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::FORBIDDEN);
        $this->deferred->assertPair($original,$current);return $original;
    }
    public function assert(array $origin):array {
        $context=$this->proof->verify($origin);$this->deferred->assert($context);return $context;
    }
}
