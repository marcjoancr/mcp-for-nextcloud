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

final class WriteDraft implements WriteTool {
	public function __construct(private MailDraftOperations $drafts) {}

	public static function definition(): ToolDefinition {
		$recipient = ['type' => 'object', 'properties' => ['email' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500], 'label' => ['type' => 'string', 'maxLength' => 500]], 'required' => ['email'], 'additionalProperties' => false];
		return new ToolDefinition('mail_write_draft', 'mail', 'write_drafts', MailMessages::TOOL_WRITE_DRAFT, [
			'type' => 'object', 'properties' => [
				'account_id' => ['type' => 'integer', 'minimum' => 1],
				'draft_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Omit to create a draft; supply a draft id to update that draft.'],
				'subject' => ['type' => 'string', 'maxLength' => 1000],
                'reply_to_message_id' => ['type'=>'integer','minimum'=>1,'description'=>'An owned message ID. Its actual Message-ID establishes the reply thread. Requires mail_read_message access.'],
                'reply_mode' => ['type'=>'string','enum'=>['reply','reply_all'],'description'=>'Derive visible recipients when replying; original Bcc is never copied.'],
                'alias_id' => ['type'=>['integer','null'],'minimum'=>1,'description'=>'An existing owned sender alias; null chooses the account identity. Omit to preserve.'],
                'add_files' => ['type'=>'array','maxItems'=>10,'items'=>['type'=>'string','minLength'=>1],'description'=>'Existing Files paths to snapshot as attachments; also requires mail_attach_files and files_read. Omission preserves attachments.'],
                'remove_attachment_ids' => ['type'=>'array','maxItems'=>10,'items'=>['type'=>'integer','minimum'=>1],'description'=>'Only native attachment IDs returned by this draft. Omission preserves them.'],
                'repair_of_operation_id' => ['type'=>'string','maxLength'=>128,'description'=>'A partial operation in this connection whose verified snapshots may be reused after a fresh preview.'],
				'body_plain' => ['type' => 'string', 'maxLength' => MailDraftOperations::MAX_BODY_CHARS],
				'body_html' => ['type' => 'string', 'maxLength' => MailDraftOperations::MAX_BODY_CHARS],
				'is_html' => ['type' => 'boolean'],
				'to' => ['type' => 'array', 'maxItems' => 50, 'items' => $recipient],
				'cc' => ['type' => 'array', 'maxItems' => 50, 'items' => $recipient],
				'bcc' => ['type' => 'array', 'maxItems' => 50, 'items' => $recipient],
				'operation_id' => \OCA\Mcp\Mcp\Operations\OperationJournal::property(),
                PlanState::ARGUMENT => PlanState::property(),
			], 'required' => ['account_id', 'subject'], 'additionalProperties' => false,
		], app: 'mail');
	}

	public function execute(array $arguments, ToolContext $context): array {
		if (!\OCA\Mcp\Mcp\Confirmation\WriteGate::confirmed($arguments)) {
			return ToolResult::json($this->preview($arguments, $context));
		}
		return ToolResult::json($this->drafts->write($arguments, $context->userId));
	}

	public function preview(array $arguments, ToolContext $context): array {
		return $this->drafts->previewWrite($arguments, $context->userId);
	}
}
