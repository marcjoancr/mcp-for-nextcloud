<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Tools\{Tool, ToolContext, ToolDefinition};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Mail\Integration\MailBoundedReads;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;

final class ReadMessageMetadata implements Tool {
	public function __construct(private MailBoundedReads $reads) {
	}

	public static function definition(): ToolDefinition {
		return new ToolDefinition('mail_read_message_metadata', 'mail', 'read_message', MailMessages::TOOL_READ_MESSAGE_METADATA, [
			'type' => 'object', 'required' => ['message_ids'], 'additionalProperties' => false,
			'properties' => [
				'message_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => MailBoundedReads::MAX_ROWS, 'uniqueItems' => true,
					'items' => ['type' => 'integer', 'minimum' => 1]],
			],
		], app: 'mail');
	}

	public function execute(array $arguments, ToolContext $context): array {
		return ToolResult::json($this->reads->messageMetadata($context->userId, $arguments));
	}
}
