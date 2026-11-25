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
use OCA\Mcp\Modules\Mail\Drafts\MailOwnedDraftOperations;

final class ReadOwnedDrafts implements Tool {
	public function __construct(private MailOwnedDraftOperations $drafts) {}
	public static function definition(): ToolDefinition {
		return new ToolDefinition('mail_read_owned_drafts', 'mail', 'read_owned_drafts',
			'Read only MCP-owned encrypted local plain-text drafts for one owned account. These records are separate from native Mail drafts and cannot be sent by Mail.',
			['type' => 'object', 'properties' => [
				'account_id' => ['type' => 'integer', 'minimum' => 1],
				'owned_draft_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], 'offset' => ['type' => 'integer', 'minimum' => 0],
			], 'required' => ['account_id'], 'additionalProperties' => false], app: 'mail');
	}
	public function execute(array $arguments, ToolContext $context): array { return ToolResult::json($this->drafts->read($arguments, $context->userId)); }
}
