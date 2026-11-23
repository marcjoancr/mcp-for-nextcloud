<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Calendar\Appointments\AppointmentBookingReader;

final class ListAppointmentBookings implements Tool {
    public const NAME = 'calendar_list_appointment_bookings';

    public function __construct(private AppointmentBookingReader $reader) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: self::NAME, module: 'calendar', operation: 'read', app: 'calendar',
            description: 'Lists pending and confirmed bookings for your own visible appointment calendars in a window of at most 93 days. Returns scheduling dates, calendar, appointment and guest names.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object)[
                    'from' => DavCollectionSchema::text('Inclusive ISO date or timestamp', 10, 40),
                    'to' => DavCollectionSchema::text('Exclusive ISO date or timestamp', 10, 40),
                    'appointment_id' => ['type' => 'integer', 'minimum' => 1],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000, 'default' => 0],
                ],
                'additionalProperties' => false,
                'required' => ['from', 'to'],
            ],
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        return ToolResult::json($this->reader->list($arguments, $context->userId));
    }
}
