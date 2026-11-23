<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Calendar\Appointments\AppointmentGateway;

final class ListAppointmentConfigs implements Tool
{
    public function __construct(private AppointmentGateway $appointments) {}
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition('calendar_list_appointments', 'calendar', 'read', 'Lists your appointment booking configurations and their owner-only booking links.', ['type' => 'object', 'properties' => (object)[], 'additionalProperties' => false], app: 'calendar');
    }
    public function execute(array $arguments, ToolContext $context): array
    {
        return ToolResult::json($this->appointments->list($context->userId));
    }
}
