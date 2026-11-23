<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Calendar\Exchange\CalendarExchange;

final class ImportIcs implements WriteTool {
    public const NAME = CalendarExchange::IMPORT;

    public function __construct(private CalendarExchange $exchange) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: self::NAME, module: 'calendar', operation: 'create', app: 'calendar',
            description: 'Imports up to 100 events from an authorized .ics file into your own calendar. Shows duplicates and copies events without sending invitations. Copies omit organizers, attendees, alarms and attachments. Repeat after approval with confirm=true and the plan_state.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object)[
                    'calendar' => DavCollectionSchema::calendar('Owned destination calendar path'),
                    'path' => DavCollectionSchema::text('Absolute path inside your Nextcloud Files', 1, 1024),
                    PlanState::ARGUMENT => PlanState::property(),
                ],
                'additionalProperties' => false,
                'required' => ['calendar', 'path'],
            ],
            grantAllOf: ['files' => ['read']],
        );
    }

    public function preview(array $arguments, ToolContext $context): array {
        return $this->exchange->preview($arguments, $context->userId);
    }

    public function execute(array $arguments, ToolContext $context): array {
        return $this->exchange->import($arguments, $context->userId);
    }
}
