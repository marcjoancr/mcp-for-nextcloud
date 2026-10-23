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
final class EditCardLabels implements WriteTool {
    public const TOOL = 'deck_edit_card_labels';
    public function __construct(private \OCA\Mcp\Modules\Deck\Labels\LabelEditing $operation, private PreviewBoundary $previews) {}
    public static function definition(): ToolDefinition {
        return new ToolDefinition(self::TOOL, 'deck', 'edit', CapabilityMessages::TOOL_EDIT_LABELS_DESCRIPTION, DeckSchema::schema(['cardId'=>DeckSchema::integer('Card ID.'),'add'=>['type'=>'array','items'=>['type'=>'integer','minimum'=>1],'maxItems'=>100],'remove'=>['type'=>'array','items'=>['type'=>'integer','minimum'=>1],'maxItems'=>100],'confirm_shared'=>DeckSchema::confirmShared(),PlanState::ARGUMENT=>PlanState::property()],['cardId']), 'deck');
    }
    public function execute(array $arguments, ToolContext $context): array {
        return $this->operation->handle($arguments, $context->userId);
    }
    public function preview(array $arguments, ToolContext $context): array {
        return $this->previews->run(self::TOOL, $context->userId, fn()=> $this->operation->preview($arguments, $context->userId));
    }
}
