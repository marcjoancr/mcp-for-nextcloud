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

final class UpdateAppointmentConfig implements WriteTool
{
    public function __construct(private AppointmentGateway $appointments) {}
    public static function definition(): ToolDefinition
    {
        $schema = CreateAppointmentConfig::schema();
        $properties = (array)$schema['properties'];
        foreach ($properties as &$property) {
            if (is_array($property)) { unset($property['default']); }
        }
        unset($property);
        $schema['properties'] = ['id' => ['type' => 'integer', 'minimum' => 1]] + $properties;
        $schema['required'] = ['id'];
        return new ToolDefinition('calendar_update_appointment', 'calendar', 'edit', 'Updates only the appointment fields you provide, preserving omitted settings. Review the changed schedule and visibility and approve the exact preview before confirming.', $schema, app: 'calendar');
    }
    public function execute(array $arguments, ToolContext $context): array
    {
        return ToolResult::json($this->appointments->update($arguments, $context->userId, self::definition()->name));
    }
    public function preview(array $arguments, ToolContext $context): array
    {
        return $this->appointments->previewUpdate($arguments, $context->userId, self::definition()->name);
    }
}
