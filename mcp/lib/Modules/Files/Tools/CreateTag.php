<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Files\Tags\NativeTags;
use OCA\Mcp\Modules\Files\Tags\TagsMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Confirmation\PlanState;

final class CreateTag implements WriteTool {
    public function __construct(private NativeTags $tags) {}
    public static function definition(): ToolDefinition {
        return new ToolDefinition(name: 'files_create_tag', module: 'files', operation: 'tags_create', description: TagsMessages::createTool(), inputSchema: [
            'type' => 'object', 'properties' => ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64], PlanState::ARGUMENT => PlanState::property()], 'additionalProperties' => false, 'required' => ['name'],
        ]);
    }
    public function preview(array $arguments, ToolContext $context): array { return $this->tags->createPreview($arguments['name'], $context->userId); }
    public function execute(array $arguments, ToolContext $context): array { return NodeAccess::run(fn (): array => ToolResult::json($this->tags->create($arguments['name'], $context->userId, $arguments['plan_state'] ?? ''))); }
}
