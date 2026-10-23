<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Deck\Execution\PreviewBoundary;
use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;
use OCA\Mcp\Modules\Deck\Presentation\CapabilityMessages;
final class ReadCardDependencies implements Tool {
    public const TOOL = 'deck_read_card_dependencies';
    public function __construct(private \OCA\Mcp\Modules\Deck\Dependencies\DependencyReading $operation) {}
    public static function definition(): ToolDefinition {
        return new ToolDefinition(self::TOOL, 'deck', 'read', CapabilityMessages::TOOL_READ_DEPENDENCIES_DESCRIPTION, DeckSchema::schema(['cardId'=>DeckSchema::integer('Card ID.')],['cardId']), 'deck');
    }
    public function execute(array $arguments, ToolContext $context): array {
        return $this->operation->handle($arguments, $context->userId);
    }
}
