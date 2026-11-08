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
use OCA\Mcp\Modules\Mail\Files\MailAttachmentSaver;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;

final class SaveAttachment implements WriteTool {
	public function __construct(private MailAttachmentSaver $saver) {}

	public static function definition(): ToolDefinition {
		return new ToolDefinition('mail_save_attachment', 'mail', 'save_attachment', MailMessages::TOOL_SAVE_ATTACHMENT, [
			'type' => 'object', 'properties' => [
				'message_id' => ['type' => 'integer', 'minimum' => 1],
				'attachment_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
				'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2048, 'description' => 'Full path of the new file in your Files area.'],
				'confirm_shared' => ['type' => 'boolean', 'description' => 'Acknowledge saving in a shared destination after showing the shared access details.'],
				'operation_id' => \OCA\Mcp\Mcp\Operations\OperationJournal::property(),
                PlanState::ARGUMENT => PlanState::property(),
			], 'required' => ['message_id', 'attachment_id', 'path'], 'additionalProperties' => false,
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
