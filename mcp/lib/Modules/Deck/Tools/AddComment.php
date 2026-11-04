<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Deck\Tools;
use OCA\Mcp\Modules\Deck\CommentOperations;
use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;
use OCA\Mcp\Modules\Deck\Presentation\CapabilityMessages;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Deck\Execution\PreviewBoundary;
use OCA\Mcp\Modules\Deck\Execution\ResultBoundary;
final class AddComment implements WriteTool {
	public const TOOL='deck_add_comment';
	public function __construct(
		private CommentOperations $operation,
		private PreviewBoundary $previews,
		private ResultBoundary $results,
	) {}
	public static function definition(): ToolDefinition {
		return new ToolDefinition(
			self::TOOL,
			'deck',
			'comment',
			CapabilityMessages::TOOL_ADD_COMMENT_DESCRIPTION,
			DeckSchema::schema([
				'cardId' => DeckSchema::integer('Card ID.', 1),
				'message' => DeckSchema::string('Verbatim comment text.', 1, 1000),
				'confirm' => ['type' => 'boolean', 'description' => 'Set true only after the preview has been approved.'],
				'confirm_shared' => DeckSchema::confirmShared(),
				PlanState::ARGUMENT => PlanState::property(),
			], ['cardId', 'message']),
			'deck',
		);
	}
	public function execute(array $arguments, ToolContext $context): array {
		return $this->results->run(self::TOOL, 'deck_read_card', fn() => $this->operation->handle($arguments, $context->userId));
	}

	public function preview(array $arguments, ToolContext $context): array {
		return $this->previews->run(self::TOOL, $context->userId, fn() => $this->operation->preview($arguments, $context->userId));
	}
}
