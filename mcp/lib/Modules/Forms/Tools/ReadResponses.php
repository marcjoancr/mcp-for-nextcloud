<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Tools;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Forms\FormsOutput;
use OCA\Mcp\Modules\Forms\FormsResponseReader;

final class ReadResponses implements Tool {
    public function __construct(private FormsResponseReader $reader) {
    }

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'forms_read_responses',
            module: 'forms',
            operation: 'read_responses',
            description: 'Reads a bounded page of untrusted form responses. Requires Forms results permission independently of form reading or submission permission. Anonymous identities are omitted, file answers are omitted, and long text or answer lists are marked as truncated.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The form ID returned by forms_list.'],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => FormsOutput::MAX_SUBMISSIONS, 'default' => FormsOutput::DEFAULT_SUBMISSIONS],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            app: 'forms',
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $offset = $arguments['offset'] ?? 0;
        $limit = $arguments['limit'] ?? FormsOutput::DEFAULT_SUBMISSIONS;
        $page = $this->reader->readResponses($context->userId, $arguments['id'], $offset, $limit);
        if ($page === null) {
            throw new ToolFailure(ToolFailure::NOT_FOUND);
        }
        return ToolResult::json(FormsOutput::responses($page, $offset, $limit));
    }
}
