<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Trash;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventBuilder;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Events\EventMapper;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Platform\Dav\CalDav\CalendarTrashStore;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess;

/** Reads native trash rows only after native principal ACL filtering and the module's calendar resource scope. */
final class CalendarTrashReader
{
    public const MAX_SCAN = 500;
    public const MAX_RESULTS = 100;
    public function __construct(
        private ScopedCollectionAccess $access,
        private CalendarTrashStore $trash,
        private EventRepository $events,
        private Classification $classification,
        private EventBuilder $builder = new EventBuilder(),
    ) {}

    public function execute(array $arguments, string $actor): array
    {
        $limit = $arguments['limit'] ?? 50;
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_RESULTS) throw new \InvalidArgumentException('limit must be between 1 and 100.');
        $calendars = isset($arguments['calendar']) ? [$this->access->resolve($actor, (string)$arguments['calendar'])] : $this->access->visible($actor);
        $byId = [];
        foreach ($calendars as $calendar) if ($calendar->ownerId === $actor) $byId[$calendar->id] = $calendar;
        $rows = $this->trash->deletedForPrincipal('principals/users/' . $actor);
        $truncated = count($rows) > self::MAX_SCAN;
        $rows = array_slice($rows, 0, self::MAX_SCAN);
        $items = [];
        foreach ($rows as $row) {
            if (!isset($row['id'], $row['calendarid']) || strtolower((string)($row['component'] ?? '')) !== 'vevent') continue;
            $calendar = $byId[(int)$row['calendarid']] ?? null;
            if ($calendar === null) continue;
            $detail = $this->trash->deletedByIdForPrincipal((int)$row['id'], 'principals/users/' . $actor);
            if ($detail === null || !isset($detail['calendardata'])) continue;
            $vcalendar = $this->events->parse((string)$detail['calendardata']);
            $event = null;
            if ($vcalendar !== null) {
                foreach ($vcalendar->select('VEVENT') as $component) {
                    if (!isset($component->{'RECURRENCE-ID'})) { $event = $component; break; }
                }
            }
            if ($vcalendar === null || $event === null || !isset($event->UID) || !isset($event->DTSTART)) continue;
            $visibility = $this->classification->visibilityOf($event, $vcalendar, $calendar, $actor);
            if ($visibility === Classification::HIDDEN) continue;
            ['start'=>$start, 'end'=>$end, 'allDay'=>$allDay] = $this->builder->timing($event);
            $item = [
                'object_id' => (int)$row['id'], 'calendar' => $calendar->path,
                'summary' => $visibility === Classification::BUSY ? EventMapper::BUSY_SUMMARY : (string)($event->SUMMARY ?? ''),
                'start' => $allDay ? $start->format('Y-m-d') : $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
                'end' => $allDay ? $end->format('Y-m-d') : $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
                'allDay' => $allDay,
                'etag' => (string)($detail['etag'] ?? $row['etag'] ?? ''),
            ];
            if ($visibility === Classification::FULL) {
                $item['uid'] = (string)$event->UID;
                $deletedAt = $detail['deleted_at'] ?? $detail['{http://nextcloud.com/ns}deleted-at'] ?? null;
                if (is_numeric($deletedAt)) {
                    $item['deletedAt'] = (new DateTimeImmutable('@' . (int)$deletedAt))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
                }
            }
            $items[] = $item;
            if (count($items) > $limit) { array_pop($items); $truncated = true; break; }
        }
        return ToolResult::json(['items'=>$items, 'limit'=>$limit, 'truncated'=>$truncated]);
    }
}
