<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Access;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Operations\ActorIdentity;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
/** Trusted origin descriptor for one admitted action, never a client-supplied delegation. */
final class FormsOriginProof {
    private const REFS=['form_id','owner_id','submission_id','share_id','comment_id','source_digest','files_operations','bulk_authority'];
    public function __construct(private PlanState $proof,private ActorIdentity $actors) {}
    public function create(string $uid,int $connection,string $tool,array $operations,array $references):array {
        if($uid===''||$connection<0||!str_starts_with($tool,'forms_')||array_diff(array_keys($references),self::REFS)!==[])throw new \InvalidArgumentException('Invalid Forms origin.');
        if(isset($references['bulk_authority']))$references['bulk_authority']=\OCA\Mcp\Modules\Forms\Execution\FormsBulkAuthority::checked($references['bulk_authority']);
        $data=['v'=>1,'uid'=>$uid,'actor'=>$this->actors->capture($uid),'connection'=>$connection,'tool'=>$tool,
            'operations'=>array_values(array_unique($operations)),'references'=>$references];
        return $data+['proof'=>$this->proof->of('forms_origin_v1',$uid,$data)];
    }
    public function verify(array $origin):array {
        $signature=$origin['proof']??'';unset($origin['proof']);
        if(array_keys($origin)!==['v','uid','actor','connection','tool','operations','references']||$origin['v']!==1
            ||!is_string($origin['uid'])||!is_string($origin['actor'])||!is_int($origin['connection'])||$origin['connection']<0
            ||!is_string($origin['tool'])||!is_array($origin['operations'])||!is_array($origin['references'])
            ||array_diff(array_keys($origin['references']),self::REFS)!==[]||!is_string($signature)
            ||!hash_equals($this->proof->of('forms_origin_v1',$origin['uid'],$origin),$signature))throw new ToolFailure('Original Forms authority is unavailable.');
        if(isset($origin['references']['bulk_authority']))\OCA\Mcp\Modules\Forms\Execution\FormsBulkAuthority::checked($origin['references']['bulk_authority']);
        $this->actors->assertCurrent($origin['uid'],$origin['actor']);
        return $origin;
    }
}
