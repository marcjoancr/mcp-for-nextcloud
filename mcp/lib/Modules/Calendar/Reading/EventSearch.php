<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Reading;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventDates;
use OCA\Mcp\Modules\Calendar\Events\EventExpander;
use OCA\Mcp\Modules\Calendar\Events\EventMapper;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarResult;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use OCP\IUserManager;

/** Searches visible event text without using hidden event content as a match oracle. */
final class EventSearch
{
    public const MAX_WINDOW_SECONDS = 31 * 86400;
    public const MAX_OBJECTS_PER_CALENDAR = 2000;
    public const MAX_OBJECTS_TOTAL = 5000;
    public const MAX_OCCURRENCES_TOTAL = 10000;
    public const MAX_RESULTS = 100;
    public const DEFAULT_LIMIT = 20;
    public function __construct(
        private ScopedCollectionAccess $access, private ObjectStore $store, private EventRangeQuery $ranges,
        private EventRepository $events, private Classification $classification, private EventExpander $expander,
        private EventMapper $mapper, private EventDates $dates, private ITimeFactory $time, private LoggerInterface $logger,
        private ?IUserManager $users = null,
    ) {}
    /** @param array{from:string,to:string,query?:string,calendar?:string,offset?:int,limit?:int,participation_status?:string} $arguments */
    public function execute(array $arguments, string $userId): array
    {
        $participation = $arguments['participation_status'] ?? null;
        $participationStatus = null;
        if ($participation !== null) {
            $statuses = ['needs-action' => 'NEEDS-ACTION', 'accepted' => 'ACCEPTED', 'declined' => 'DECLINED', 'tentative' => 'TENTATIVE'];
            if (!is_string($participation) || !isset($statuses[$participation])) throw new CalendarArgumentException('participation_status must be needs-action, accepted, declined, or tentative.', 'participation_status', 'invalid');
            $participationStatus = $statuses[$participation];
        }
        $actorEmail = null;
        if ($participationStatus !== null && $this->users !== null) {
            $actor = $this->users->get($userId);
            $email = null;
            if ($actor?->isEnabled()) {
                $systemEmail = $actor->getSystemEMailAddress();
                $email = is_string($systemEmail) && $systemEmail !== '' ? $systemEmail : $actor->getEMailAddress();
            }
            $actorEmail = is_string($email) && $email !== '' ? 'mailto:' . $email : null;
        }
        $query = $arguments['query'] ?? '';
        if (!is_string($query) || strlen($query) > 200) throw new CalendarArgumentException('query must be at most 200 characters.', 'query', 'invalid length');
        $offset = $arguments['offset'] ?? 0; $limit = $arguments['limit'] ?? self::DEFAULT_LIMIT;
        if (!is_int($offset) || $offset < 0 || $offset > 10000) throw new CalendarArgumentException('offset must be between 0 and 10000.', 'offset', 'out of range');
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_RESULTS) throw new CalendarArgumentException('limit must be between 1 and 100.', 'limit', 'out of range');
        [$from, $to] = $this->dates->window($arguments['from'] ?? null, $arguments['to'] ?? null, $this->time->now());
        if ($to->getTimestamp() - $from->getTimestamp() > self::MAX_WINDOW_SECONDS) throw CalendarException::limit('Event search window exceeds 31 days.');
        $calendars = isset($arguments['calendar']) ? [$this->access->resolve($userId, $arguments['calendar'])] : $this->access->visible($userId);
        $items = []; $truncated = false; $objectCount = 0; $occurrenceCount = 0; $needle = mb_strtolower(trim($query), 'UTF-8');
        foreach ($calendars as $calendar) {
            // Invitation replies operate on the actor's own copy only. A writable shared calendar is not an invitation inbox.
            if ($participationStatus !== null && $calendar->ownedByOther($userId)) continue;
            $uris = $this->ranges->eventUrisInRange($calendar->id, $from, $to);
            if (count($uris) > self::MAX_OBJECTS_PER_CALENDAR) { $uris = array_slice($uris, 0, self::MAX_OBJECTS_PER_CALENDAR); $truncated = true; }
            if ($objectCount + count($uris) > self::MAX_OBJECTS_TOTAL) { $uris = array_slice($uris, 0, max(0, self::MAX_OBJECTS_TOTAL - $objectCount)); $truncated = true; }
            $objectCount += count($uris);
            foreach ($this->store->objects($calendar->id, $uris) as $row) {
                $vcalendar = $this->events->parse($row['data']);
                if ($vcalendar === null) { $this->logger->warning('MCP calendar search: invalid object skipped', ['app' => 'mcp', 'exception_class' => 'Sabre\\VObject\\ParseException']); continue; }
                foreach ($this->expander->occurrences($vcalendar, $from, $to) as $occurrence) {
                    if (++$occurrenceCount > self::MAX_OCCURRENCES_TOTAL) { $truncated = true; break 2; }
                    $event = $occurrence['event'];
                    $visibility = $this->classification->visibilityOf($event, $vcalendar, $calendar, $userId);
                    // Inspect searchable text only after CLASS grants full visibility.
                    if ($visibility !== Classification::FULL) continue;
                    $haystack = mb_strtolower(implode("\n", [(string)($event->SUMMARY ?? ''), (string)($event->LOCATION ?? ''), (string)($event->DESCRIPTION ?? '')]), 'UTF-8');
                    if ($needle !== '' && !str_contains($haystack, $needle)) continue;
                    if ($participationStatus !== null && ($actorEmail === null || $this->organizerIsActor($event, $actorEmail)
                        || !$this->hasActorParticipation($event, $actorEmail, $participationStatus))) continue;
                    $items[] = $this->mapper->toItem($event, $occurrence['start'], $occurrence['end'], $calendar, $visibility, $occurrence['recurrenceId'] ?? null);
                }
            }
        }
        usort($items, static fn(array $a, array $b): int => [$a['start'], $a['calendar'], $a['uid']] <=> [$b['start'], $b['calendar'], $b['uid']]);
        $total = count($items); $page = array_slice($items, $offset, $limit); $hasMore = $offset + count($page) < $total || $truncated;
        return CalendarResult::json(['items'=>$page, 'offset'=>$offset, 'limit'=>$limit, 'hasMore'=>$hasMore, 'nextOffset'=>$hasMore ? $offset + count($page) : null, 'truncated'=>$truncated]);
    }

    private function organizerIsActor(\Sabre\VObject\Component\VEvent $event, string $email): bool
    {
        $organizer = (string)($event->ORGANIZER ?? '');
        return str_starts_with(strtolower($organizer), 'mailto:') && strcasecmp($organizer, $email) === 0;
    }

    private function hasActorParticipation(\Sabre\VObject\Component\VEvent $event, string $email, string $status): bool
    {
        $matching = array_values(array_filter($event->select('ATTENDEE'), static fn ($attendee): bool => strcasecmp((string)$attendee, $email) === 0));
        return count($matching) === 1 && strtoupper((string)($matching[0]['PARTSTAT'] ?? 'NEEDS-ACTION')) === $status;
    }
}
