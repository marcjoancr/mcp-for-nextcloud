<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Exchange;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;

final class IcsBundle {
    public const MAX_BYTES = 1048576;
    public const MAX_EVENTS = 100;
    private const MAX_COMPONENTS = 300;

    /** @return list<array{uid: string, summary: string, calendar: VCalendar, stripped: bool}> */
    public function importable(string $data): array {
        $this->assertSize($data);
        try {
            $source = Reader::read($data);
        } catch (ParseException) {
            throw new ToolFailure('The file is not a valid iCalendar document.');
        }
        if (!$source instanceof VCalendar) {
            throw new ToolFailure('The file is not a valid iCalendar document.');
        }
        $groups = [];
        $this->assertEventCalendar($source);
        foreach ($source->select('VEVENT') as $event) {
            $uid = (string)($event->UID ?? '');
            if ($uid === '' || mb_strlen($uid) > 255 || preg_match('/[\x00-\x1f\x7f]/', $uid)) {
                throw new ToolFailure('Each imported event must have a valid UID.');
            }
            $groups['uid:' . $uid][] = $event;
        }
        if ($groups === [] || count($groups) > self::MAX_EVENTS) {
            throw new ToolFailure('Select between 1 and 100 events for iCalendar exchange.');
        }

        $output = [];
        foreach ($groups as $events) {
            $uid = (string)$events[0]->UID;
            $calendar = $this->envelope();
            $master = null;
            $instances = [];
            $stripped = isset($source->METHOD);
            foreach ($events as $original) {
                if (!isset($original->DTSTART)) {
                    throw new ToolFailure('An imported event is missing its start time.');
                }
                if (!isset($original->{'RECURRENCE-ID'})) {
                    if ($master !== null) {
                        throw new ToolFailure('The iCalendar file defines the same event more than once.');
                    }
                    $master = $original;
                } else {
                    $instance = (string)$original->{'RECURRENCE-ID'};
                    if (isset($instances[$instance])) {
                        throw new ToolFailure('The iCalendar file defines the same occurrence more than once.');
                    }
                    $instances[$instance] = true;
                }
                $event = clone $original;
                $stripped = $this->sanitize($event, true) || $stripped;
                $calendar->add($event);
            }
            if ($master === null) {
                throw new ToolFailure('An imported occurrence requires its recurring event.');
            }
            $timezones = [];
            $usedZones = [];
            $this->referencedTimezones($calendar, $usedZones);
            $this->appendTimezones($calendar, $source, $timezones, $usedZones);
            $this->assertSize($calendar->serialize());
            $output[] = [
                'uid' => $uid,
                'summary' => (string)($master->SUMMARY ?? ''),
                'calendar' => $calendar,
                'stripped' => $stripped,
            ];
        }
        return $output;
    }

    /** @param list<VCalendar> $calendars */
    public function export(array $calendars): string {
        $result = $this->envelope();
        $timezones = [];
        $bytes = 0;
        foreach ($calendars as $source) {
            $this->assertEventCalendar($source);
            foreach ($source->select('VEVENT') as $original) {
                $event = clone $original;
                $this->sanitize($event, false);
                $bytes += strlen($event->serialize());
                if ($bytes > self::MAX_BYTES) {
                    throw new ToolFailure('The iCalendar document exceeds the 1 MiB limit.');
                }
                $result->add($event);
            }
            $this->appendTimezones($result, $source, $timezones);
        }
        $data = $result->serialize();
        $this->assertSize($data);
        return $data;
    }

    private function assertSize(string $data): void {
        if (strlen($data) > self::MAX_BYTES) {
            throw new ToolFailure('The iCalendar document exceeds the 1 MiB limit.');
        }
    }

    private function assertEventCalendar(VCalendar $calendar): void {
        $children = $calendar->getComponents();
        if (count($children) > self::MAX_COMPONENTS) {
            throw new ToolFailure('The iCalendar document contains too many components.');
        }
        foreach ($children as $child) {
            if (!in_array($child->name, ['VEVENT', 'VTIMEZONE'], true)) {
                throw new ToolFailure('iCalendar exchange supports events and their time zones.');
            }
        }
    }

    private function envelope(): VCalendar {
        return new VCalendar(['VERSION' => '2.0', 'PRODID' => '-//MCP for Nextcloud//Calendar exchange//EN']);
    }

    /** @param array<string, true> $ids */
    private function referencedTimezones(Component $parent, array &$ids): void {
        foreach ($parent->children() as $node) {
            if ($node instanceof Component) {
                $this->referencedTimezones($node, $ids);
            } elseif (isset($node['TZID'])) {
                $ids[(string)$node['TZID']] = true;
            }
        }
    }

    /** @param array<string, string> $timezones @param array<string, true>|null $used */
    private function appendTimezones(VCalendar $result, VCalendar $source, array &$timezones, ?array $used = null): void {
        foreach ($source->select('VTIMEZONE') as $timezone) {
            $id = (string)($timezone->TZID ?? '');
            if ($used !== null && !isset($used[$id])) {
                continue;
            }
            $serialized = $timezone->serialize();
            if ($id === '' || (isset($timezones[$id]) && $timezones[$id] !== $serialized)) {
                throw new ToolFailure('The iCalendar document has conflicting time zone definitions.');
            }
            if (!isset($timezones[$id])) {
                $timezones[$id] = $serialized;
                $result->add(clone $timezone);
            }
        }
    }

    private function sanitize(Component $component, bool $import): bool {
        $changed = false;
        foreach ($component->children() as $child) {
            if (str_starts_with($child->name, 'X-NC-')
                || ($import && in_array($child->name, ['ORGANIZER', 'ATTENDEE', 'VALARM', 'ATTACH'], true))) {
                $component->remove($child);
                $changed = true;
            } elseif ($child instanceof Component) {
                $changed = $this->sanitize($child, $import) || $changed;
            }
        }
        return $changed;
    }
}
