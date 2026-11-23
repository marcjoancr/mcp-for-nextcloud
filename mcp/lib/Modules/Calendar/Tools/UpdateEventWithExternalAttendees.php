<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Calendar\Editing\EventEditor;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Execution\EventApproval;

final class UpdateEventWithExternalAttendees implements WriteTool {
    public const NAME = 'calendar_update_event_with_external_attendees';

    public function __construct(private EventEditor $operation, private EventApproval $approval) {}

    public static function definition(): ToolDefinition {
        $schema = UpdateEvent::definition()->inputSchema;
        $properties = array_intersect_key((array)$schema['properties'], array_flip([
            'calendar', 'uid', 'etag', 'send_invitations', 'confirm_shared',
        ]));
        $properties['add_attendee_emails'] = CreateEventWithExternalAttendees::addressList('Addresses to add, preserving the other attendees.');
        $properties['remove_attendee_emails'] = CreateEventWithExternalAttendees::addressList('Addresses to remove, as shown by the event or the preview.');
        $schema['properties'] = (object)$properties;

        return new ToolDefinition(
            name: self::NAME,
            module: 'calendar',
            operation: 'edit',
            description: 'Adds or removes explicitly selected attendee email addresses on a single event you organize. Provide at least one change. Show the preview for explicit approval, then repeat with confirm=true and the preview etag. Confirm a shared calendar separately. Invitations and cancellations are disabled unless send_invitations=true.',
            inputSchema: $schema,
            app: 'calendar',
            destructiveHint: true,
        );
    }

    public function preview(array $arguments, ToolContext $context): array {
        if (($arguments['add_attendee_emails'] ?? []) !== []) {
            $this->approval->assertExternalAttendeesEnabled();
        }
        return $this->approval->preview(self::NAME, $this->operation, $arguments, $context->userId);
    }

    public function execute(array $arguments, ToolContext $context): array {
        if (($arguments['add_attendee_emails'] ?? []) !== []) {
            $this->approval->assertExternalAttendeesEnabled();
        }
        if (!isset($arguments['etag'])) {
            throw new CalendarArgumentException(Translator::t('Provide the ETag from the event preview.'), 'etag');
        }
        return $this->approval->execute($this->operation, $arguments, $context->userId);
    }
}
