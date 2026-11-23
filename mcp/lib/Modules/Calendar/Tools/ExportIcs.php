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
use OCA\Mcp\Modules\Calendar\Exchange\CalendarExchange;
use OCA\Mcp\Modules\Calendar\Exchange\IcsBundle;

final class ExportIcs implements Tool {
    public const NAME = 'calendar_export_ics';

    public function __construct(private CalendarExchange $exchange) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: self::NAME, module: 'calendar', operation: 'read', app: 'calendar',
            description: 'Exports selected fully visible events and series from one calendar as an iCalendar document of up to 1 MiB.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object)[
                    'calendar' => DavCollectionSchema::calendar('Visible source calendar path'),
                    'uids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => IcsBundle::MAX_EVENTS, 'items' => DavCollectionSchema::uid()],
                ],
                'additionalProperties' => false,
                'required' => ['calendar', 'uids'],
            ],
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        return $this->exchange->export($arguments, $context->userId);
    }
}
