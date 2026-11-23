<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Tools;

use OCA\Mcp\Mcp\Tools\DavCollectionSchema;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Calendar\Creation\EventCreator;
use OCA\Mcp\Modules\Calendar\Execution\EventApproval;
use OCA\Mcp\Modules\Calendar\Scheduling\AttendeeResolver;

final class CreateEventWithExternalAttendees implements WriteTool {
    public const NAME = 'calendar_create_event_with_external_attendees';

    public function __construct(private EventCreator $operation, private EventApproval $approval) {}

    public static function definition(): ToolDefinition {
        $schema = CreateEvent::definition()->inputSchema;
        $properties = (array)$schema['properties'];
        $properties['external_attendees'] = self::addressList('Bare email addresses selected by the user. Their availability is unknown.');
        $schema['properties'] = (object)$properties;
        $schema['required'][] = 'external_attendees';

        return new ToolDefinition(
            name: self::NAME,
            module: 'calendar',
            operation: 'create',
            description: 'Creates an event with explicitly selected attendee email addresses and optional internal account IDs. Read Contacts first to select an address if needed. The preview lists recipients and scheduling effects. Show it and repeat only after explicit approval, with confirm=true and confirm_shared=true when the user also approves a shared calendar. Invitations are disabled unless send_invitations=true.',
            inputSchema: $schema,
            app: 'calendar',
            destructiveHint: true,
        );
    }

    /** @return array<string, mixed> */
    public static function addressList(string $description): array {
        return [
            'type' => 'array',
            'items' => DavCollectionSchema::text('Bare email address', 3, 254),
            'minItems' => 1,
            'maxItems' => AttendeeResolver::MAX_ATTENDEES,
            'uniqueItems' => true,
            'description' => $description,
        ];
    }

    public function preview(array $arguments, ToolContext $context): array {
        $this->approval->assertExternalAttendeesEnabled();
        return $this->approval->preview(self::NAME, $this->operation, $arguments, $context->userId);
    }

    public function execute(array $arguments, ToolContext $context): array {
        $this->approval->assertExternalAttendeesEnabled();
        return $this->approval->execute($this->operation, $arguments, $context->userId);
    }
}
