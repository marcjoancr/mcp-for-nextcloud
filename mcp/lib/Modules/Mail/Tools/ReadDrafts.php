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
use OCA\Mcp\Modules\Mail\Drafts\MailDraftOperations;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;

final class ReadDrafts implements Tool {
	public function __construct(private MailDraftOperations $drafts) {}

	public static function definition(): ToolDefinition {
		return new ToolDefinition('mail_read_drafts', 'mail', 'read_drafts', MailMessages::TOOL_READ_DRAFTS, [
			'type' => 'object', 'properties' => [
				'draft_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Omit to list drafts; supply a draft id returned by that list to read one draft.'],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MailDraftOperations::MAX_DRAFTS],
				'offset' => ['type' => 'integer', 'minimum' => 0],
			], 'additionalProperties' => false,
		], app: 'mail');
	}

	public function execute(array $arguments, ToolContext $context): array {
		return ToolResult::json($this->drafts->read($arguments, $context->userId));
	}
}
