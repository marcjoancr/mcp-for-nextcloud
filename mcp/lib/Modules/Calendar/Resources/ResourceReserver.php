<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Resources;

use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventBuilder;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Execution\EventWriteOperation;
use OCA\Mcp\Modules\Calendar\Execution\PreparedEventWrite;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarResult;
use OCA\Mcp\Modules\Calendar\Scheduling\Scheduling;
use OCA\Mcp\Modules\Calendar\Scheduling\AttendeeResolver;
use OCA\Mcp\Platform\Dav\CalDav\ResourceAwareCalDavWriter;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice;
use OCA\Mcp\Modules\Calendar\Events\StoredEvent;
use OCA\Mcp\Platform\Dav\CalDav\CalDavMessages;
use OCP\AppFramework\Utility\ITimeFactory;
use Sabre\VObject\Reader;
use Sabre\VObject\Component\VCalendar;

/** Adds one native resource principal as an attendee through the CalDAV scheduling pipeline. */
final class ResourceReserver implements EventWriteOperation
{
    public function __construct(
        private ScopedCollectionAccess $access,
        private SharedCollectionNotice $sharedGuard,
        private ResourceAwareCalDavWriter $dav,
        private ObjectStore $store,
        private EventRepository $events,
        private CalendarResourceDirectory $directory,
        private EventBuilder $builder,
        private Scheduling $scheduling,
        private ITimeFactory $time,
        private AttendeeResolver $attendees,
    ) {}

    public function execute(array $arguments, string $userId): array
    {
        $prepared = $this->prepare($arguments, $userId);
        return is_array($prepared) ? $prepared : $prepared->dispatch();
    }

    public function prepare(array $arguments, string $userId): PreparedEventWrite|array
    {
        if (($arguments['confirm'] ?? false) === true && (!is_string($arguments['etag'] ?? null) || trim($arguments['etag']) === '')) {
            throw new CalendarArgumentException('Confirming a resource booking requires the etag returned by its plan.','etag','required when confirming');
        }
        $resource = $this->directory->resolve($userId, $arguments['type'], $arguments['resource']);
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar']);
        if ($calendar->ownerId !== $userId) throw CalendarException::blocked('A resource can only be booked from a calendar owned by the acting user.');
        if (($arguments['confirm_shared'] ?? false) !== true && ($notice = $this->sharedGuard->forCollection($calendar, $userId)) !== null) {
            return \OCA\Mcp\Mcp\Tools\Results\ToolResult::json($notice);
        }
        $stored = $this->events->forChange($calendar, $arguments['uid'], $userId, $arguments['etag'] ?? null);
        if ($stored->recurring()) throw CalendarException::blocked('Booking a resource on a recurring event is not supported.');
        $master = $stored->master() ?? throw CalendarException::notFound();
        $organizer = $this->attendees->organizer($userId);
        if (isset($master->ORGANIZER)) {
            $storedOrganizer = strtolower(preg_replace('/^mailto:/i', '', (string)$master->ORGANIZER) ?? '');
            if ($storedOrganizer !== strtolower($organizer['email'])) throw CalendarException::blocked('Only the event organizer can book a resource.');
        }
        $email = strtolower($resource['email']);
        if ($email === '') throw CalendarException::blocked('This resource has no booking address.');
        foreach ($master->select('ATTENDEE') as $attendee) {
            if (strtolower((string)$attendee) === 'mailto:' . $email) throw CalendarException::conflict('This resource is already on the event.');
        }
        $beforeCalendar = Reader::read($stored->vcalendar->serialize(), Reader::OPTION_FORGIVING);
        $after = Reader::read($stored->vcalendar->serialize(), Reader::OPTION_FORGIVING);
        $event = $after->VEVENT ?? throw CalendarException::notFound();
        if (!isset($event->ORGANIZER)) $event->add('ORGANIZER','mailto:'.$organizer['email'],['CN'=>$organizer['displayName']]);
        $event->add('ATTENDEE', 'mailto:' . $email, [
            'CN' => $resource['name'],
            'CUTYPE' => $resource['type'],
            'ROLE' => 'REQ-PARTICIPANT',
            'PARTSTAT' => 'NEEDS-ACTION',
            'RSVP' => 'TRUE',
        ]);
        $this->builder->touch($event, $this->time->now());
        $before = new StoredEvent($stored->id, $stored->uri, $stored->etag, $beforeCalendar);
        // A resource booking is an invitation request; saving only the attendee line would not reserve it.
        $notify = true;
        return new PreparedEventWrite($calendar, null, $before, $after,
            function () use ($calendar, $stored, $after, $resource, $email, $notify, $userId): array {
                $this->dav->updateWithResourcePrincipals($userId, $calendar->uri, $stored->uri, $stored->etag, $after->serialize(), $notify);
                $row = $this->store->object($calendar->id, $stored->uri) ?? throw new \RuntimeException(CalDavMessages::DAV_FAILURE);
                $written = $this->events->parse($row['data']) ?? throw new \RuntimeException(CalDavMessages::DAV_FAILURE);
                $attendee = null;
                foreach (($written->VEVENT?->select('ATTENDEE') ?? []) as $candidate) {
                    if (strtolower((string)$candidate) === 'mailto:' . $email) { $attendee = $candidate; break; }
                }
                $resourceStatus = $attendee === null ? 'unknown' : strtoupper((string)($attendee['PARTSTAT'] ?? 'NEEDS-ACTION'));
                return CalendarResult::json([
                    'uid' => (string)$written->VEVENT->UID,
                    'calendar' => $calendar->path,
                    'resource' => ['id' => $resource['id'], 'type' => $resource['type'], 'name' => $resource['name']],
                    'eventUpdated' => true,
                    'resourceBooking' => [
                        'status' => $resourceStatus,
                        'scheduleStatus' => $attendee === null || !isset($attendee['SCHEDULE-STATUS']) ? null : (string)$attendee['SCHEDULE-STATUS'],
                        'message' => $resourceStatus === 'ACCEPTED' ? 'The resource attendee accepted the request.' : 'The event was updated; the resource booking state is not confirmed.',
                    ],
                    'scheduling' => $this->scheduling->report($notify, $written),
                    'etag' => $row['etag'],
                ]);
            }
        );
    }
}
