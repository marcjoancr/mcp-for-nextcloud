<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Tools;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Forms\FormsOutput;
use OCA\Mcp\Modules\Forms\FormsReader;

final class ListForms implements Tool {
    public function __construct(private FormsReader $reader) {
    }

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'forms_list',
            module: 'forms',
            operation: 'read',
            description: 'Lists Nextcloud Forms visible to the current user.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => FormsOutput::MAX_FORMS, 'default' => FormsOutput::DEFAULT_FORMS],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                ],
                'additionalProperties' => false,
            ],
            app: 'forms',
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $limit = $arguments['limit'] ?? FormsOutput::DEFAULT_FORMS;
        $offset = $arguments['offset'] ?? 0;
        $forms = $this->reader->list($context->userId);
        $page = array_slice($forms, $offset, $limit + 1);
        $hasMore = count($page) > $limit;
        if ($hasMore) {
            array_pop($page);
        }
        return ToolResult::json([
            'forms' => array_map(FormsOutput::summary(...), $page),
            'hasMore' => $hasMore,
            'nextOffset' => $hasMore ? $offset + count($page) : null,
        ]);
    }
}
