<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Calendar\Appointments\AppointmentGateway;

final class CreateAppointmentConfig implements WriteTool
{
    public function __construct(private AppointmentGateway $appointments) {}
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition('calendar_create_appointment', 'calendar', 'create', 'Creates a native appointment booking configuration in one of your calendars after your explicit approval. Visibility defaults to PRIVATE; PRIVATE hides it from your public profile, but anyone with its booking URL can book. Choose PUBLIC only when you also want it listed on your public profile. The booking URL is returned only to you after confirmation.', self::schema(), app: 'calendar', destructiveHint: false);
    }
    public function execute(array $arguments, ToolContext $context): array
    {
        return ToolResult::json($this->appointments->create($arguments, $context->userId, self::definition()->name));
    }
    public function preview(array $arguments, ToolContext $context): array
    {
        return $this->appointments->previewCreate($arguments, $context->userId, self::definition()->name);
    }
    public static function schema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => self::properties(),
            'required' => ['calendar', 'name', 'availability', 'length', 'increment'],
        ];
    }
    public static function properties(): array
    {
        $slot = ['type' => 'object', 'properties' => ['start' => ['type' => 'string', 'pattern' => '^(?:[01]\\d|2[0-3]):[0-5]\\d$'], 'end' => ['type' => 'string', 'pattern' => '^(?:[01]\\d|2[0-3]):[0-5]\\d$']], 'required' => ['start', 'end'], 'additionalProperties' => false];
        $days = [];
        foreach (['MO','TU','WE','TH','FR','SA','SU'] as $day) { $days[$day] = ['type' => 'array', 'maxItems' => 12, 'items' => $slot]; }
        return [
            \OCA\Mcp\Mcp\Confirmation\PlanState::ARGUMENT => \OCA\Mcp\Mcp\Confirmation\PlanState::property(),
            'calendar' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024, 'description' => 'Visible writable calendar path from calendar_list_calendars.'],
            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            'description' => ['type' => 'string', 'maxLength' => 1000, 'default' => ''],
            'location' => ['type' => ['string','null'], 'maxLength' => 255],
            'availability' => ['type' => 'object', 'properties' => ['timezoneId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64], 'slots' => ['type' => 'object', 'properties' => $days, 'required' => array_keys($days), 'additionalProperties' => false]], 'required' => ['timezoneId','slots'], 'additionalProperties' => false],
            'length' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 720],
            'increment' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 720],
            'visibility' => ['type' => 'string', 'enum' => ['PRIVATE','PUBLIC'], 'default' => 'PRIVATE'],
            'preparationDuration' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1440, 'default' => 0],
            'followupDuration' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1440, 'default' => 0],
            'buffer' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1440, 'default' => 0],
            'dailyMax' => ['type' => ['integer','null'], 'minimum' => 1, 'maximum' => 100],
            'start' => ['type' => ['integer','null'], 'description' => 'Optional start of the booking window as Unix timestamp.'],
            'end' => ['type' => ['integer','null'], 'description' => 'Optional end of the booking window as Unix timestamp.'],
            'futureLimit' => ['type' => ['integer','null'], 'minimum' => 1, 'maximum' => 365],
        ];
    }
}
