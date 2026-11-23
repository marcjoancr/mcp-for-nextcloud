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

final class DeleteAppointmentConfig implements WriteTool
{
    public function __construct(private AppointmentGateway $appointments) {}
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition('calendar_delete_appointment', 'calendar', 'delete', 'Removes one of your appointment booking configurations after your explicit approval and stops future bookings. Existing booked events stay in your calendar.', ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'minimum' => 1], \OCA\Mcp\Mcp\Confirmation\PlanState::ARGUMENT => \OCA\Mcp\Mcp\Confirmation\PlanState::property()], 'required' => ['id'], 'additionalProperties' => false], app: 'calendar', destructiveHint: true);
    }
    public function execute(array $arguments, ToolContext $context): array
    {
        return ToolResult::json($this->appointments->delete($arguments, $context->userId, self::definition()->name));
    }
    public function preview(array $arguments, ToolContext $context): array
    {
        return $this->appointments->previewDelete($arguments, $context->userId, self::definition()->name);
    }
}
