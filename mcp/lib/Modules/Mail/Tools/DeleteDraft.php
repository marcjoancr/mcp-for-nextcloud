<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Mail\Drafts\MailDraftOperations;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;

final class DeleteDraft implements WriteTool {
	public function __construct(private MailDraftOperations $drafts) {}

	public static function definition(): ToolDefinition {
		return new ToolDefinition('mail_delete_draft', 'mail', 'delete_drafts', MailMessages::TOOL_DELETE_DRAFT, [
			'type' => 'object', 'properties' => ['draft_id' => ['type' => 'integer', 'minimum' => 1], 'operation_id' => \OCA\Mcp\Mcp\Operations\OperationJournal::property(), PlanState::ARGUMENT => PlanState::property()],
			'required' => ['draft_id'], 'additionalProperties' => false,
		], app: 'mail');
	}

	public function execute(array $arguments, ToolContext $context): array {
		if (!\OCA\Mcp\Mcp\Confirmation\WriteGate::confirmed($arguments)) {
			return ToolResult::json($this->preview($arguments, $context));
		}
		return ToolResult::json($this->drafts->delete($arguments, $context->userId));
	}

	public function preview(array $arguments, ToolContext $context): array {
		return $this->drafts->previewDelete($arguments, $context->userId);
	}
}
