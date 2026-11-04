<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Forms\FormsOutput;
use OCA\Mcp\Modules\Forms\Responses\FormsResponseExport;

final class ExportResponses implements WriteTool {
	public function __construct(private FormsResponseExport $export) {}

	public static function definition(): ToolDefinition {
		return new ToolDefinition(
			name: 'forms_export_responses', module: 'forms', operation: 'export_responses',
			description: 'Previews and exports a bounded, formula-safe CSV of form responses to Files. Review the destination and confirm the plan before writing.',
			inputSchema: ['type' => 'object', 'properties' => [
				'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The form ID returned by forms_list.'],
				'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024, 'description' => 'Destination path in Files; the name must end in .csv.'],
				'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => FormsOutput::MAX_SUBMISSIONS, 'default' => FormsOutput::DEFAULT_SUBMISSIONS],
				'confirm' => ['type' => 'boolean', 'description' => 'Set true only after reviewing the current export plan.'],
				'confirm_shared' => ['type' => 'boolean', 'description' => 'Set true only after reviewing the shared destination warning.'],
				PlanState::ARGUMENT => PlanState::property(),
			], 'required' => ['id', 'path'], 'additionalProperties' => false],
			app: 'forms', grantAllOf: ['files' => ['create']],
		);
	}

	public function execute(array $arguments, ToolContext $context): array {
		return ToolResult::json($this->export->execute($context->userId, $arguments));
	}

	public function preview(array $arguments, ToolContext $context): array {
		return $this->export->preview($context->userId, $arguments);
	}
}
