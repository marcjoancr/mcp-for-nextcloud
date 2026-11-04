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
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;
use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Modules\Files\Tags\TagsMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Confirmation\PlanState;

final class SetFileTags implements WriteTool {
    public function __construct(private NativeTags $tags) {}
    public static function definition(): ToolDefinition {
        return new ToolDefinition(name: 'files_set_tags', module: 'files', operation: 'tags_edit', description: TagsMessages::setTool(), inputSchema: [
            'type' => 'object', 'properties' => [
                'paths' => ['type' => 'array', 'minItems' => 1, 'maxItems' => NativeTags::MAX_PATHS, 'items' => ['type' => 'string', 'minLength' => 1, 'description' => ReadingMessages::path()]],
                'add' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64]],
                'remove' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64]],
                'confirm_shared' => ['type' => 'boolean', 'description' => EditingMessages::confirmShared()],
                PlanState::ARGUMENT => PlanState::property(),
            ], 'additionalProperties' => false, 'required' => ['paths', 'add'],
        ]);
    }
    public function preview(array $arguments, ToolContext $context): array { return NodeAccess::run(fn (): array => $this->tags->setPreview($arguments['paths'], $arguments['add'], $arguments['remove'] ?? [], $context->userId)); }
    public function execute(array $arguments, ToolContext $context): array { return NodeAccess::run(fn (): array => ToolResult::json($this->tags->set($arguments['paths'], $arguments['add'], $arguments['remove'] ?? [], $context->userId, $arguments['plan_state'] ?? '', (bool)($arguments['confirm_shared'] ?? false)))); }
}
