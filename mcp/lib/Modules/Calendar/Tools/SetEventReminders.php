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
use OCA\Mcp\Modules\Calendar\Editing\EventReminders;

final class SetEventReminders implements WriteTool {
    public const NAME = EventReminders::TOOL;

    public function __construct(private EventReminders $reminders) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: self::NAME, module: 'calendar', operation: 'edit', app: 'calendar',
            description: 'Sets standard display reminders in minutes before the start of an event or series. Preserves custom, e-mail, repeated and absolute alarms and occurrence-specific reminders. Pass an empty list to clear supported display reminders. Show the preview and repeat with confirm=true and its plan_state.',
            inputSchema: [
                'type' => 'object',
                'properties' => (object)[
                    'calendar' => DavCollectionSchema::calendar('Writable calendar path'),
                    'uid' => DavCollectionSchema::uid(),
                    'minutes_before' => [
                        'type' => 'array', 'maxItems' => EventReminders::MAX_REMINDERS,
                        'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => EventReminders::MAX_MINUTES],
                    ],
                    PlanState::ARGUMENT => PlanState::property(),
                    'confirm_shared' => ['type' => 'boolean', 'description' => 'Acknowledge the owner notice after approving a shared calendar change.'],
                ],
                'additionalProperties' => false,
                'required' => ['calendar', 'uid', 'minutes_before'],
            ],
        );
    }

    public function preview(array $arguments, ToolContext $context): array {
        return $this->reminders->preview($arguments, $context->userId);
    }

    public function execute(array $arguments, ToolContext $context): array {
        return $this->reminders->execute($arguments, $context->userId);
    }
}
