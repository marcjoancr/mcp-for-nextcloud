<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Support\Calendar;
final class CalendarModuleFactory {
    public static function create($listCalendars, $listEvents, $availability, $createEvent, $updateEvent, $moveEvent, $deleteEvent, $transferEvent, $approval, $references, array $extensions = []): \OCA\Mcp\Modules\Calendar\CalendarModule {
        $factories = [
            'calendar_list_calendars' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\ListCalendars($listCalendars),
            'calendar_list_events' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\ListEvents($listEvents),
            'calendar_check_availability' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\CheckAvailability($availability),
            'calendar_create_event' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\CreateEvent($createEvent, $approval),
            'calendar_update_event' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\UpdateEvent($updateEvent, $approval),
            'calendar_create_event_with_external_attendees' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\CreateEventWithExternalAttendees($createEvent, $approval),
            'calendar_update_event_with_external_attendees' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\UpdateEventWithExternalAttendees($updateEvent, $approval),
            'calendar_move_event' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\MoveEvent($moveEvent, $approval),
            'calendar_delete_event' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\DeleteEvent($deleteEvent, $approval),
            'calendar_transfer_event' => static fn () => new \OCA\Mcp\Modules\Calendar\Tools\TransferEvent($transferEvent, $approval, $references),
        ] + $extensions;
        foreach (\OCA\Mcp\Modules\Calendar\CalendarToolCatalog::definitions() as $name => $definition) {
            $factories[$name] ??= static fn () => throw new \LogicException('Missing fixture for ' . $name);
        }
        return new \OCA\Mcp\Modules\Calendar\CalendarModule($factories, $references->assertAvailable(...));
    }
}
