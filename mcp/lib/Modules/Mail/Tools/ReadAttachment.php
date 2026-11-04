<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Mail\Attachments\MailAttachmentAccess;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;

final class ReadAttachment implements Tool {
	public function __construct(private MailAttachmentAccess $access) {}

	public static function definition(): ToolDefinition {
		return new ToolDefinition('mail_read_attachment', 'mail', 'read_attachment', MailMessages::TOOL_READ_ATTACHMENT, [
			'type' => 'object', 'properties' => [
				'message_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Message id returned by a Mail search or read tool.'],
				'attachment_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => 'MIME part id from the attachment list; omit to list attachments.'],
			], 'required' => ['message_id'], 'additionalProperties' => false,
		], app: 'mail');
	}

	public function execute(array $arguments, ToolContext $context): array {
		$result = isset($arguments['attachment_id'])
			? $this->access->read($context->userId, (int)$arguments['message_id'], (string)$arguments['attachment_id'])
			: ['attachments' => $this->access->list($context->userId, (int)$arguments['message_id'])];
		return ToolResult::json($result);
	}
}
