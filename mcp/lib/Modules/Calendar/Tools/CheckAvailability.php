<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Read-only availability lookup across accessible calendars. */
final class CheckAvailability implements Tool
{
	public const NAME = 'calendar_check_availability';

	public function __construct(private \OCA\Mcp\Modules\Calendar\Reading\AvailabilityReader $operation)
	{
	}

	public static function definition(): ToolDefinition
	{
		return new ToolDefinition(
			name: self::NAME,
			module: 'calendar',
			operation: 'availability',
			description: 'Check busy times, conflicts and free intervals in accessible calendars',
			inputSchema: [
				'type' => 'object',
				'properties' => (object) [
					'from' => DavCollectionSchema::text('inclusive start ISO date or timestamp', 10, 40),
					'to' => DavCollectionSchema::text('exclusive end ISO date or timestamp', 10, 40),
					'calendars' => [
						'type' => 'array',
						'minItems' => 1,
						'maxItems' => 20,
						'items' => DavCollectionSchema::calendar('accessible calendar path'),
						'description' => 'Calendar paths from calendar_list_calendars; omit to check all visible calendars.',
					],
					'min_duration_minutes' => [
						'type' => 'integer',
						'minimum' => 5,
						'maximum' => 1440,
						'default' => 30,
						'description' => 'Minimum length of returned free ranges, in minutes.',
					],
				],
				'required' => ['from', 'to'],
				'additionalProperties' => false,
			],
			app: 'calendar',
		);
	}

	public function execute(array $arguments, ToolContext $context): array
	{
		return $this->operation->execute($arguments, $context->userId);
	}
}
