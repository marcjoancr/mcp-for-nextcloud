<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Tools;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Forms\FormsOutput;
use OCA\Mcp\Modules\Forms\Responses\FormsResponseSummary;

final class SummarizeResponses implements Tool {
	public function __construct(private FormsResponseSummary $summary) {}

	public static function definition(): ToolDefinition {
		return new ToolDefinition(
			name: 'forms_summarize_responses', module: 'forms', operation: 'summarize_responses',
			description: 'Counts exact answer values for each question in a bounded response page. Anonymous identities and file answers are omitted; partial results are marked.',
			inputSchema: ['type' => 'object', 'properties' => [
				'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The form ID returned by forms_list.'],
				'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => FormsOutput::MAX_SUBMISSIONS, 'default' => FormsOutput::DEFAULT_SUBMISSIONS],
			], 'required' => ['id'], 'additionalProperties' => false], app: 'forms',
		);
	}

	public function execute(array $arguments, ToolContext $context): array {
		return ToolResult::json($this->summary->summarize($context->userId, $arguments));
	}
}
