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
use OCA\Mcp\Modules\Forms\Execution\FormsBulkImpactCursor;
use OCA\Mcp\Modules\Forms\Comments\FormsRecoveredCommentsReader;
use OCA\Mcp\Modules\Forms\Presentation\FormsProjection;
/** Read locators carry no authority. Trusted callbacks admit current and original tools on every read. */
final class FormsRecoveryReads {
    public const ACTIONS=['forms_read_operation_impact','forms_read_recovered_comments'];
    public function __construct(private FormsBulkWorkflow $bulk,private FormsBulkImpactCursor $cursors,private FormsRecoveredCommentsReader $comments) {}
    public function snapshot(string $uid,string $tool,array $args,object $guard,\Closure $history,\Closure $capture):array {
        if($tool==='forms_read_recovered_comments') {
            $refs=$history($args['target_operation_id']);$authority=FormsBulkAuthority::checked($refs['bulk_authority']??null);
            $status=$capture($tool,[],['bulk_authority'=>$authority]);
            return $this->comments->snapshot($uid,$args['target_operation_id'],$refs,$guard,$status,
                static fn(int $restored,array $original):array=>$capture($tool,['id'=>$restored],['form'=>['id'=>$restored,'ownerId'=>$uid],'bulk_authority'=>$original]));
        }
        if($tool!=='forms_read_operation_impact')throw new ToolFailure('Unknown Forms recovery read.');
        $target=$this->cursors->verify($uid,$args['cursor']);$origin=$capture($target['tool'],$target['input'],[]);
        $snapshot=$guard->bulkSnapshot($uid,$target['tool'],FormsBulkWorkflow::nativeInput($target['tool'],$target['input'])+['_mcp_origin'=>$origin]);
        if(($snapshot['revision']??null)!==$target['revision'])throw new ToolFailure('The Forms effect preview changed. Request a new approval.');
        $snapshot=FormsBulkWorkflow::authorizationSnapshot($target['tool'],$snapshot);
        $origin=$capture($target['tool'],$target['input'],$snapshot);$authority=$this->authority($origin);
        $capture($tool,[],['bulk_authority'=>$authority]);
        return FormsProjection::bounded(['revision'=>'impact-read:'.hash('sha256',json_encode([$target['revision'],$args['cursor'],$args['limit']??100],JSON_THROW_ON_ERROR)),
            'bulk_authority'=>$authority,'form'=>array_intersect_key($snapshot['form']??[],array_flip(['id','ownerId']))]);
    }
    public function read(string $uid,string $tool,array $args,array $snapshot,object $guard,\Closure $capture,\Closure $files):array {
        if($tool==='forms_read_recovered_comments') {
            $restored=$snapshot['restored_form_id'];
            $snapshot['comments_origin']=$capture($tool,['id'=>$restored],['form'=>['id'=>$restored,'ownerId'=>$uid],'bulk_authority'=>$snapshot['bulk_authority']]);
            return $this->comments->read($uid,$args['target_operation_id'],$snapshot,$args['cursor']??null,$args['limit']??100,$guard);
        }
        if($tool!=='forms_read_operation_impact')throw new ToolFailure('Unknown Forms recovery read.');
        return $this->bulk->inspect($uid,$args['cursor'],$args['limit']??100,$guard,
            function(string $original,array $input,array $view)use($tool,$capture,$snapshot):array {
                $origin=$capture($original,$input,$view);
                $authority=$view===[]?FormsBulkAuthority::checked($snapshot['bulk_authority']):$this->authority($origin);
                $capture($tool,[],['bulk_authority'=>$authority]);return $origin;
            },$files);
    }
    private function authority(array $origin):array {
        return FormsBulkAuthority::checked(['tool'=>$origin['tool']??null,'required_operations'=>$origin['operations']??null,'files_operations'=>$origin['references']['files_operations']??[]]);
    }
}
