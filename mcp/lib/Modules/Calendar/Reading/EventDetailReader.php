<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Reading;
use DateTimeZone;
use OCA\Mcp\Modules\Calendar\Contracts\EventReferenceNotFound;
use OCA\Mcp\Modules\Calendar\Contracts\EventReferenceReader;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventDates;
use OCA\Mcp\Modules\Calendar\Events\EventExpander;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Events\EventOccurrenceResolver;
use OCA\Mcp\Modules\Calendar\Events\EventBuilder;
use OCA\Mcp\Modules\Calendar\Events\EventMapper;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarResult;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Component\VCalendar;

/** Reads one visible event or occurrence. Hidden events answer exactly like missing references. */
final class EventDetailReader
{
    public function __construct(private ScopedCollectionAccess $access, private ObjectStore $store,
        private EventRepository $events, private Classification $classification, private EventExpander $expander,
        private EventDates $dates, private EventReferenceReader $references, private EventBuilder $builder = new EventBuilder()) {}

    /** @param array{calendar:string,uid:string,recurrence_id?:string} $arguments */
    public function execute(array $arguments, string $userId): array
    {
        $calendar = $this->access->resolve($userId, $arguments['calendar']);
        $row = $this->store->objectByUid($calendar->id, $arguments['uid'], 'VEVENT');
        $vcalendar = $row === null || $row['deleted'] ? null : $this->events->parse($row['data']);
        if ($vcalendar === null) throw CalendarException::notFound();
        $event = $this->selectEvent($vcalendar, $arguments['recurrence_id'] ?? null);
        if ($event === null || !isset($event->UID) || !isset($event->DTSTART)) throw CalendarException::notFound();
        $visibility = $this->classification->visibilityOf($event, $vcalendar, $calendar, $userId);
        if ($visibility === Classification::HIDDEN) throw CalendarException::notFound();
        ['start' => $start, 'end' => $end, 'allDay' => $allDay, 'timeZone' => $timeZone] = $this->builder->timing($event);
        $item = [
            'uid' => (string)($event->UID ?? ''), 'calendar' => $calendar->path,
            'summary' => $visibility === Classification::BUSY ? EventMapper::BUSY_SUMMARY : (string)($event->SUMMARY ?? ''),
            'start' => $allDay ? $start->format('Y-m-d') : $start->format('Y-m-d\TH:i:s\Z'),
            'end' => $allDay ? $end->format('Y-m-d') : $end->format('Y-m-d\TH:i:s\Z'),
            'allDay' => $allDay,
        ];
        $recurrenceId = $this->recurrenceId($event, $arguments['recurrence_id'] ?? null, $allDay);
        if ($recurrenceId !== null) $item['recurrence_id'] = $recurrenceId;
        if ($visibility === Classification::FULL) {
            if ($timeZone !== null) {
                $item['timeZone'] = $timeZone->getName();
            }
            $item['status'] = strtoupper((string)($event->STATUS ?? 'CONFIRMED'));
            $item['location'] = (string)($event->LOCATION ?? '');
            $item['description'] = (string)($event->DESCRIPTION ?? '');
            $item['organizer'] = isset($event->ORGANIZER) ? (string)$event->ORGANIZER : null;
            $item['attendees'] = array_map(static fn($a): array => [
                'email' => (string)$a,
                'name' => (string)($a['CN'] ?? ''),
                'partstat' => strtoupper((string)($a['PARTSTAT'] ?? 'NEEDS-ACTION')),
                'role' => (string)($a['ROLE'] ?? ''),
            ], $event->select('ATTENDEE'));
            try { $item['url'] = $this->references->read($userId, $calendar->path, (string)$event->UID)['url']; }
            catch (EventReferenceNotFound) { /* Link is optional; the detail has already passed its own visibility check. */ }
        }
        return CalendarResult::json($item);
    }

    private function recurrenceId(VEvent $event, ?string $requested, bool $allDay): ?string
    {
        $property = $event->{'RECURRENCE-ID'} ?? null;
        if ($property === null && $requested === null) return null;
        if ($requested !== null && $this->dates->isDateOnly($requested)) return $this->dates->day($requested, 'recurrence_id')->format('Y-m-d');
        $date = $property !== null ? $property->getDateTime(new DateTimeZone('UTC')) : $this->dates->parse((string)$requested, 'recurrence_id');
        return $allDay ? $date->format('Y-m-d') : $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function selectEvent(VCalendar $calendar, ?string $recurrenceId): ?VEvent
    {
        if ($recurrenceId !== null) return (new EventOccurrenceResolver($this->expander, $this->dates, $this->builder))->find($calendar, $recurrenceId);
        foreach ($calendar->select('VEVENT') as $event) if (!isset($event->{'RECURRENCE-ID'})) return $event;
        return null;
    }

}
