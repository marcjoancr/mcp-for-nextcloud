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

final class WriteOwnedDraft implements WriteTool {
	public function __construct(private MailOwnedDraftOperations $drafts) {}
	public static function definition(): ToolDefinition {
		$recipient = ['type' => 'object', 'properties' => ['email' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500], 'label' => ['type' => 'string', 'maxLength' => 500]], 'required' => ['email'], 'additionalProperties' => false];
		return new ToolDefinition('mail_write_owned_draft', 'mail', 'write_owned_draft',
			'Preview and confirm a private MCP-owned plain-text local draft. Create when owned_draft_id is omitted; update with its current revision. This never writes a native Mail draft, IMAP mailbox, SMTP queue, or sends email.',
			['type' => 'object', 'properties' => [
				'account_id' => ['type' => 'integer', 'minimum' => 1],
				'owned_draft_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
				'revision' => ['type' => 'integer', 'minimum' => 1],
				'subject' => ['type' => 'string', 'maxLength' => MailOwnedDraftOperations::MAX_SUBJECT_CHARS],
				'body_plain' => ['type' => 'string', 'maxLength' => MailOwnedDraftOperations::MAX_BODY_CHARS],
				'to' => ['type' => 'array', 'maxItems' => 50, 'items' => $recipient],
				'cc' => ['type' => 'array', 'maxItems' => 50, 'items' => $recipient],
				'bcc' => ['type' => 'array', 'maxItems' => 50, 'items' => $recipient],
				PlanState::ARGUMENT => PlanState::property(),
			], 'required' => ['account_id', 'subject', 'body_plain'], 'additionalProperties' => false], app: 'mail');
	}
	public function preview(array $arguments, ToolContext $context): array { return $this->drafts->previewWrite($arguments, $context->userId); }
	public function execute(array $arguments, ToolContext $context): array {
		return ToolResult::json(WriteGate::confirmed($arguments) ? $this->drafts->write($arguments, $context->userId) : $this->preview($arguments, $context));
	}
}
