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
use OCA\Mcp\Modules\Forms\FormsReader;

final class ReadForm implements Tool {
    public function __construct(private FormsReader $reader) {
    }

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'forms_read',
            module: 'forms',
            operation: 'read',
            description: 'Reads a Nextcloud Form structure, questions and options, without submissions.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The form ID returned by forms_list.'],
                    'question_offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                    'question_limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => FormsOutput::MAX_QUESTIONS, 'default' => FormsOutput::DEFAULT_QUESTIONS],
                    'option_offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                    'option_limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => FormsOutput::MAX_OPTIONS, 'default' => FormsOutput::DEFAULT_OPTIONS],
                ],
                'required' => ['id'],
                'additionalProperties' => false,
            ],
            app: 'forms',
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $form = $this->reader->read($context->userId, $arguments['id']);
        if ($form === null) {
            throw new ToolFailure(ToolFailure::NOT_FOUND);
        }
        return ToolResult::json(FormsOutput::form(
            $form,
            $arguments['question_offset'] ?? 0,
            $arguments['question_limit'] ?? FormsOutput::DEFAULT_QUESTIONS,
            $arguments['option_offset'] ?? 0,
            $arguments['option_limit'] ?? FormsOutput::DEFAULT_OPTIONS,
        ));
    }
}
