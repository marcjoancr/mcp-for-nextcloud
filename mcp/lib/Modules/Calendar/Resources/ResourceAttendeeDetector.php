<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Resources;

use Sabre\VObject\Component\VCalendar;

/** Detects explicit native resource attendee types already present in a stored event. */
final class ResourceAttendeeDetector
{
    public static function contains(VCalendar $calendar): bool
    {
        foreach ($calendar->select('VEVENT') as $event) {
            foreach ($event->select('ATTENDEE') as $attendee) {
                if (in_array(strtoupper((string)($attendee['CUTYPE'] ?? '')), [CalendarResourceDirectory::ROOM, CalendarResourceDirectory::RESOURCE], true)) return true;
            }
        }
        return false;
    }
}
