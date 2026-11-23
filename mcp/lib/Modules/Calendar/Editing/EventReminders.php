<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Editing;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Events\StoredEvent;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Platform\Dav\CalDav\CalDavWriter;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess;
use OCA\Mcp\Platform\Dav\Collections\Collection;
use Sabre\VObject\Component;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\InvalidDataException;

final class EventReminders {
    public const TOOL = 'calendar_set_event_reminders';
    public const MAX_MINUTES = 10080;
    public const MAX_REMINDERS = 5;

    public function __construct(
        private ScopedCollectionAccess $access,
        private EventRepository $events,
        private CalDavWriter $dav,
        private SharedCollectionNotice $shared,
        private PlanState $states,
    ) {}

    public function preview(array $args, string $actor): array {
        return $this->prepare($args, $actor)[0];
    }

    public function execute(array $args, string $actor): array {
        PlanState::require($args);
        [$plan, $calendar, $stored] = $this->prepare($args, $actor);
        if (!PlanState::matches($plan[PlanState::ARGUMENT], $args)) {
            throw new PlanChanged($plan);
        }
        if ($plan['shared'] !== [] && ($args['confirm_shared'] ?? false) !== true) {
            throw new ToolFailure('Confirm the changes to the shared calendar separately.');
        }

        if ($plan['before'] !== $plan['after']) {
            $event = $stored->master() ?? throw CalendarException::notFound();
            foreach ($event->select('VALARM') as $alarm) {
                if ($this->managedMinutes($alarm) !== null) {
                    $event->remove($alarm);
                }
            }
            foreach ($plan['after'] as $minutes) {
                $event->add('VALARM', [
                    'ACTION' => 'DISPLAY',
                    'TRIGGER' => $minutes === 0 ? 'PT0S' : '-PT' . $minutes . 'M',
                    'DESCRIPTION' => 'Reminder',
                ]);
            }
            $this->dav->update($actor, $calendar->uri, $stored->uri, $stored->etag, $stored->vcalendar->serialize(), false);
            $fresh = $this->events->forChange($calendar, $args['uid'], $actor, null);
            if ($this->minutes($fresh)[0] !== $plan['after']) {
                throw new ToolFailure('The updated reminders could not be verified.');
            }
            $stored = $fresh;
        }
        return ToolResult::json([
            'calendar' => $calendar->path, 'uid' => $args['uid'], 'etag' => $stored->etag,
            'minutes_before' => $plan['after'], 'changed' => $plan['before'] !== $plan['after'],
        ]);
    }

    /** @return array{array<string,mixed>,Collection,StoredEvent} */
    private function prepare(array $args, string $actor): array {
        $calendar = $this->access->resolveWritable($actor, $args['calendar']);
        $stored = $this->events->forChange($calendar, $args['uid'], $actor, null);
        [$before, $preserved] = $this->minutes($stored);
        $after = $args['minutes_before'];
        if (!is_array($after) || count($after) > self::MAX_REMINDERS) {
            throw new ToolFailure('Select up to five reminder times.');
        }
        foreach ($after as $value) {
            if (!is_int($value) || $value < 0 || $value > self::MAX_MINUTES) {
                throw new ToolFailure('Select reminder times between zero and 10080 minutes.');
            }
        }
        $after = array_values(array_unique($after));
        sort($after, SORT_NUMERIC);
        $notice = $this->shared->forCollection($calendar, $actor);
        $plan = [
            'action' => self::TOOL,
            'calendar' => $calendar->path,
            'uid' => $args['uid'],
            'etag' => $stored->etag,
            'summary' => (string)($stored->master()?->SUMMARY ?? ''),
            'recurring' => $stored->recurring(),
            'before' => $before,
            'after' => $after,
            'preserved_alarms' => $preserved,
            'shared' => $notice === null ? [] : [$notice],
        ];
        $plan[PlanState::ARGUMENT] = $this->states->of(self::TOOL, $actor, $plan + [
            'calendarId' => $calendar->id,
            'owner' => $calendar->ownerPrincipal,
            'objectId' => $stored->id,
            'objectUri' => $stored->uri,
        ]);
        return [$plan, $calendar, $stored];
    }

    /** @return array{list<int>,int} */
    private function minutes(StoredEvent $stored): array {
        $event = $stored->master() ?? throw CalendarException::notFound();
        $minutes = [];
        $preserved = 0;
        foreach ($event->select('VALARM') as $alarm) {
            $value = $this->managedMinutes($alarm);
            if ($value === null) {
                ++$preserved;
            } else {
                $minutes[] = $value;
            }
        }
        sort($minutes, SORT_NUMERIC);
        return [$minutes, $preserved];
    }

    private function managedMinutes(Component $alarm): ?int {
        $trigger = $alarm->TRIGGER;
        if ($trigger === null || strtoupper((string)$alarm->ACTION) !== 'DISPLAY'
            || (isset($alarm->DESCRIPTION) && (string)$alarm->DESCRIPTION !== 'Reminder')
            || strtoupper((string)($trigger['RELATED'] ?? 'START')) !== 'START'
            || strlen((string)$trigger) > 40) {
            return null;
        }
        // Maintain custom descriptions, alarm UIDs, repetitions and client state as they were created.
        foreach ($alarm->children() as $property) {
            if (!in_array($property->name, ['ACTION', 'TRIGGER', 'DESCRIPTION'], true)) {
                return null;
            }
        }
        try {
            $duration = DateTimeParser::parseDuration((string)$trigger);
        } catch (InvalidDataException) {
            return null;
        }
        $minutes = $duration->d * 1440 + $duration->h * 60 + $duration->i;
        if ($duration->s !== 0 || (!$duration->invert && $minutes !== 0) || $minutes > self::MAX_MINUTES) {
            return null;
        }
        return $minutes;
    }
}
