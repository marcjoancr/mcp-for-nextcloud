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
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Mail\Drafts\MailOwnedDraftOperations;

final class SendOwnedDraft implements WriteTool {
	public function __construct(private MailOwnedDraftOperations $drafts) {}

	public static function definition(): ToolDefinition {
		return new ToolDefinition(MailOwnedDraftOperations::SEND_TOOL, 'mail', 'send',
			'Preview and confirm one immediate send attempt for an exact private MCP draft through your official Nextcloud Mail account. Requires OAuth. Read the draft to check its recorded send state after an uncertain response.',
			['type' => 'object', 'properties' => [
				'account_id' => ['type' => 'integer', 'minimum' => 1],
				'owned_draft_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$'],
				'revision' => ['type' => 'integer', 'minimum' => 1],
				PlanState::ARGUMENT => PlanState::property(),
			], 'required' => ['account_id', 'owned_draft_id', 'revision'], 'additionalProperties' => false], app: 'mail');
	}

	public function preview(array $arguments, ToolContext $context): array {
		$plan = $this->drafts->previewSend($arguments, $context->userId);
		if (isset($plan['send_state'])) {
			throw new ToolFailure('This draft already has a send attempt. Use mail_read_owned_drafts to check its state.');
		}
		return $plan;
	}

	public function execute(array $arguments, ToolContext $context): array {
		return ToolResult::json(WriteGate::confirmed($arguments)
			? $this->drafts->send($arguments, $context->userId)
			: $this->preview($arguments, $context));
	}
}
