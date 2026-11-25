<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Mail\Drafts\MailOwnedDraftOperations;

final class DeleteOwnedDraft implements WriteTool {
	public function __construct(private MailOwnedDraftOperations $drafts) {}
	public static function definition(): ToolDefinition {
		return new ToolDefinition('mail_delete_owned_draft', 'mail', 'delete_owned_draft',
			'Preview and confirm permanent deletion of one MCP-owned local draft using its current revision. This never deletes a native Mail draft or remote message.',
			['type' => 'object', 'properties' => [
				'account_id' => ['type' => 'integer', 'minimum' => 1],
				'owned_draft_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
				'revision' => ['type' => 'integer', 'minimum' => 1],
				PlanState::ARGUMENT => PlanState::property(),
			], 'required' => ['account_id', 'owned_draft_id', 'revision'], 'additionalProperties' => false], app: 'mail');
	}
	public function preview(array $arguments, ToolContext $context): array { return $this->drafts->previewDelete($arguments, $context->userId); }
	public function execute(array $arguments, ToolContext $context): array {
		return ToolResult::json(WriteGate::confirmed($arguments) ? $this->drafts->delete($arguments, $context->userId) : $this->preview($arguments, $context));
	}
}
