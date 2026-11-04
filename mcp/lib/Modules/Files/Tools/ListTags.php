<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Files\Tags\NativeTags;
use OCA\Mcp\Modules\Files\Tags\TagsMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;

final class ListTags implements Tool {
    public function __construct(private NativeTags $tags) {}
    public static function definition(): ToolDefinition {
        return new ToolDefinition(name: 'files_list_tags', module: 'files', operation: 'tags_read', description: TagsMessages::listTool(), inputSchema: [
            'type' => 'object', 'properties' => ['path' => ['type' => 'string', 'minLength' => 1]], 'additionalProperties' => false,
        ]);
    }
    public function execute(array $arguments, ToolContext $context): array { return NodeAccess::run(fn (): array => ToolResult::json($this->tags->list($context->userId, $arguments['path'] ?? null))); }
}
