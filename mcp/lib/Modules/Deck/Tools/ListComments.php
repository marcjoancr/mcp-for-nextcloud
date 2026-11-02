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
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Deck\Execution\ResultBoundary;
final class ListComments implements Tool {
	public const TOOL='deck_list_comments';
	public function __construct(
		private CommentOperations $operation,
		private ResultBoundary $results,
	) {}
	public static function definition(): ToolDefinition {
		return new ToolDefinition(
			self::TOOL,
			'deck',
			'read_comments',
			CapabilityMessages::TOOL_LIST_COMMENTS_DESCRIPTION,
			DeckSchema::schema([
				'cardId' => DeckSchema::integer('Card ID.', 1),
				'limit' => DeckSchema::integer('Maximum comments to return.', 1, 100, 50),
				'offset' => DeckSchema::integer('Number of comments to skip.', minimum: 0, default: 0),
			], ['cardId']),
			'deck',
		);
	}
	public function execute(array $arguments, ToolContext $context): array {
		return $this->results->run(self::TOOL, 'deck_read_card', fn() => $this->operation->list($arguments, $context->userId));
	}
}
