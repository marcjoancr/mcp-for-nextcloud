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
final class UnlinkCardFile implements WriteTool {
    public const TOOL = 'deck_unlink_card_file';
    public function __construct(private \OCA\Mcp\Modules\Deck\Files\CardFileOperations $operation, private PreviewBoundary $previews) {}
    public static function definition(): ToolDefinition {
        return new ToolDefinition(self::TOOL, 'deck', 'edit', CapabilityMessages::TOOL_UNLINK_FILE_DESCRIPTION, DeckSchema::schema(['cardId'=>DeckSchema::integer('Card ID.'),'attachmentId'=>DeckSchema::integer(CapabilityMessages::PARAM_ATTACHMENT_ID),'file_state'=>DeckSchema::schema(['fileId'=>DeckSchema::integer('Original Files node ID from the plan receipt.'),'createdBy'=>DeckSchema::string('Native share initiator from the plan receipt.',1,255),'createdAt'=>DeckSchema::integer('Native share creation timestamp from the plan receipt.',0)],['fileId','createdBy','createdAt']),'confirm_shared'=>DeckSchema::confirmShared(),PlanState::ARGUMENT=>PlanState::property()],['cardId','attachmentId']), 'deck', grantAllOf: ['files'=>['read']]);
    }
    public function execute(array $arguments, ToolContext $context): array {
        return $this->operation->handle($arguments, $context->userId);
    }
    public function preview(array $arguments, ToolContext $context): array {
        return $this->previews->run(self::TOOL, $context->userId, fn()=> $this->operation->preview($arguments, $context->userId));
    }
}
