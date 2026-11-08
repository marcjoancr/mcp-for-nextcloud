<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Mcp\Tools\{WriteTool,ToolContext,ToolDefinition};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Mail\Drafts\MailDraftOperations;

/** Permission-bearing attachment action; composition and preservation still have a single implementation. */
final class AttachFiles implements WriteTool {
    public function __construct(private MailDraftOperations $drafts) {}
    public static function definition(): ToolDefinition {
        $properties = WriteDraft::definition()->inputSchema['properties'];
        $properties = array_intersect_key($properties,array_flip(['account_id','draft_id','add_files','remove_attachment_ids','repair_of_operation_id','operation_id','plan_state']));
        return new ToolDefinition('mail_attach_files','mail','attach_files',
            'Adds Files snapshots to, or removes named attachments from, an existing local unsent draft. Omitted attachments are preserved. Requires Files read access for this connection; no sharing link is created and nothing is sent.',
            ['type'=>'object','properties'=>$properties,'required'=>['account_id','draft_id'],'additionalProperties'=>false],app:'mail');
    }
    public function preview(array $arguments,ToolContext $context): array {
        return $this->drafts->previewWrite($arguments,$context->userId);
    }
    public function execute(array $arguments,ToolContext $context): array {
        if (!\OCA\Mcp\Mcp\Confirmation\WriteGate::confirmed($arguments)) return ToolResult::json($this->preview($arguments,$context));
        return ToolResult::json($this->drafts->write($arguments,$context->userId));
    }
}
