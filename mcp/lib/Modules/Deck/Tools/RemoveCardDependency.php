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
final class RemoveCardDependency implements WriteTool {
    public const TOOL = 'deck_remove_card_dependency';
    public function __construct(private \OCA\Mcp\Modules\Deck\Dependencies\DependencyEditing $operation, private PreviewBoundary $previews) {}
    public static function definition(): ToolDefinition {
        return new ToolDefinition(self::TOOL, 'deck', 'edit', CapabilityMessages::TOOL_REMOVE_DEPENDENCY_DESCRIPTION, DeckSchema::schema(['cardId'=>DeckSchema::integer('Origin card ID.'),'targetId'=>DeckSchema::integer(CapabilityMessages::PARAM_DEPENDENCY_TARGET),'confirm_shared'=>DeckSchema::confirmShared(),PlanState::ARGUMENT=>PlanState::property()],['cardId','targetId']), 'deck');
    }
    public function execute(array $arguments, ToolContext $context): array {
        return $this->operation->handle($arguments, $context->userId);
    }
    public function preview(array $arguments, ToolContext $context): array {
        return $this->previews->run(self::TOOL, $context->userId, fn()=> $this->operation->preview($arguments, $context->userId));
    }
}
