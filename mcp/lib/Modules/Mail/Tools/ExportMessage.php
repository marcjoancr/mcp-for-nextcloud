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
use OCA\Mcp\Modules\Mail\Files\MailMessageExport;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;

final class ExportMessage implements WriteTool {
	public function __construct(private MailMessageExport $saver) {}

	public static function definition(): ToolDefinition {
		return new ToolDefinition('mail_export_message', 'mail', 'export_message', 'Exports exact original EML bytes from one owned Mail message to Files, up to 25 MiB. Preview the exact source hash, size, destination and Files access policy; obtain explicit approval and repeat with confirm=true, plan_state and operation_id. Shared destinations require confirm_shared=true. Never overwrites or sends mail. Encrypted or signed messages are preserved as opaque bytes. Requires mail.export_message and files.create grants.', [
			'type' => 'object', 'properties' => [
				'message_id' => ['type' => 'integer', 'minimum' => 1],
				'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2048, 'description' => 'Full path of the new file in your Files area.'],
				'confirm_shared' => ['type' => 'boolean', 'description' => 'Acknowledge saving in a shared destination after showing the shared access details.'],
				'operation_id' => \OCA\Mcp\Mcp\Operations\OperationJournal::property(),
                PlanState::ARGUMENT => PlanState::property(),
			], 'required' => ['message_id', 'path'], 'additionalProperties' => false,
		], app: 'mail', grantAllOf: ['files' => ['create']]);
	}

	public function execute(array $arguments, ToolContext $context): array {
		if (!\OCA\Mcp\Mcp\Confirmation\WriteGate::confirmed($arguments)) {
			return ToolResult::json($this->preview($arguments, $context));
		}
		return ToolResult::json($this->saver->save($arguments, $context->userId));
	}

	public function preview(array $arguments, ToolContext $context): array {
		return $this->saver->preview($arguments, $context->userId);
	}
}
