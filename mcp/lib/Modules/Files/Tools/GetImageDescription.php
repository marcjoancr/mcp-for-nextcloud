<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Files\Descriptions\DescriptionService;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;

final class GetImageDescription implements Tool {
    public function __construct(private DescriptionService $descriptions, private FilesAccessGuard $visibility) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'files_get_image_description', module: 'files', operation: 'description_read',
            description: 'Read the saved or embedded description of an image in Files.',
            inputSchema: ['type' => 'object', 'properties' => ['path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Path relative to your Files root.']], 'required' => ['path'], 'additionalProperties' => false],
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        return NodeAccess::run(fn (): array => ToolResult::json($this->descriptions->read($context->userId, $arguments['path'], $this->visibility)));
    }
}
