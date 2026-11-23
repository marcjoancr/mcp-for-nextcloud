<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\WriteTool;
final class CalendarToolCatalog
{
    /** @return list<class-string<Tool>> */
    public static function classes(): array
    {
        return [
            Tools\ListCalendars::class,
            Tools\ListEvents::class,
            Tools\CheckAvailability::class,
            Tools\CreateEvent::class,
            Tools\UpdateEvent::class,
            Tools\MoveEvent::class,
            Tools\DeleteEvent::class,
            Tools\TransferEvent::class,
            Tools\CreateEventWithExternalAttendees::class,
            Tools\UpdateEventWithExternalAttendees::class,
            Tools\SearchEvents::class,
            Tools\ReadEvent::class,
            Tools\FindSlots::class,
            Tools\RespondToInvitation::class,
            Tools\ListResources::class,
            Tools\CheckResourceAvailability::class,
            Tools\ReserveResource::class,
            Tools\ListAppointmentConfigs::class,
            Tools\CreateAppointmentConfig::class,
            Tools\UpdateAppointmentConfig::class,
            Tools\DeleteAppointmentConfig::class,
            Tools\ListAppointmentBookings::class,
            Tools\ImportIcs::class,
            Tools\ExportIcs::class,
            Tools\SetEventReminders::class,
            Tools\CalendarCreate::class,
            Tools\ListCalendarShares::class,
            Tools\ShareCalendar::class,
            Tools\ListTrashedEvents::class,
            Tools\RestoreTrashedEvent::class,
        ];
    }
    /** @return array<string, array<string, mixed>> */
    public static function definitions(?array $classes = null): array
    {
        $result = [];
        foreach ($classes ?? self::classes() as $class) {
            if (!is_a($class, Tool::class, true)) {
                throw new \LogicException('Catalog entries must implement Tool');
            }
            $d = $class::definition();
            if (!in_array($d->operation, ['read', 'availability'], true) && !is_a($class, WriteTool::class, true)) {
                throw new \LogicException('Writes must implement WriteTool');
            }
            if (isset($result[$d->name])) {
                throw new \LogicException('Duplicate tool: ' . $d->name);
            }
            $raw = [
                'name' => $d->name,
                'description' => $d->description,
                'inputSchema' => $d->inputSchema,
                'module' => $d->module,
                'operation' => $d->operation,
                'app' => $d->app,
            ];
            if ($d->destructiveHint !== null) {
                $raw['destructiveHint'] = $d->destructiveHint;
            }
            if ($d->grantAllOf !== null) {
                $raw['grantAllOf'] = $d->grantAllOf;
            }
            $result[$d->name] = $raw;
        }
        return $result;
    }
}
