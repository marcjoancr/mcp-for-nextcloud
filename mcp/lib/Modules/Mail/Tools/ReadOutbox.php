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

final class ReadOutbox implements Tool {
	public function __construct(private MailBoundedReads $reads) {
	}

	public static function definition(): ToolDefinition {
		return new ToolDefinition('mail_read_outbox', 'mail', 'read_outbox', MailMessages::TOOL_READ_OUTBOX, [
			'type' => 'object', 'additionalProperties' => false,
			'properties' => [
				'account_id' => ['type' => 'integer', 'minimum' => 1],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MailBoundedReads::MAX_ROWS, 'default' => MailBoundedReads::DEFAULT_OUTBOX_LIMIT],
				'before_id' => ['type' => 'integer', 'minimum' => 1],
			],
		], app: 'mail');
	}

	public function execute(array $arguments, ToolContext $context): array {
		return ToolResult::json($this->reads->outbox($context->userId, $arguments));
	}
}
