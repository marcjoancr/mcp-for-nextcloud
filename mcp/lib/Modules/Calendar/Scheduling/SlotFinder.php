<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Scheduling;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\Modules\Calendar\Events\EventDates;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarResult;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityReader;
use OCA\Mcp\L10n\Translator;

/** Finds bounded, local-work-hour candidate slots and labels incomplete invitee checks as unknown. */
final class SlotFinder
{
    public const MAX_WINDOW_SECONDS = 14 * 86400;
    public const MAX_CHECKS = 250;
    public const MAX_ATTENDEES = 50;
    public const MAX_RESULTS = 20;
    public function __construct(private AvailabilityReader $organizerAvailability, private AvailabilityCheck $attendeeAvailability, private AttendeeResolver $attendees, private EventDates $dates) {}

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments, string $organizerUid): array
    {
        [$from, $to] = $this->dates->window($arguments['from'] ?? null, $arguments['to'] ?? null, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        if ($to->getTimestamp() - $from->getTimestamp() > self::MAX_WINDOW_SECONDS) throw CalendarException::limit('Slot search window exceeds 14 days.');
        $duration = $arguments['duration_minutes'] ?? 30;
        if (!is_int($duration) || $duration < 15 || $duration > 480) throw new CalendarArgumentException('duration_minutes must be between 15 and 480.', 'duration_minutes', 'out of range');
        $zoneName = $arguments['time_zone'] ?? 'UTC';
        if (!is_string($zoneName)) throw new CalendarArgumentException('time_zone must be an IANA time zone.', 'time_zone', 'invalid');
        $zone = $this->dates->timeZone($zoneName);
        $workStart = $arguments['work_start'] ?? '09:00'; $workEnd = $arguments['work_end'] ?? '17:00';
        if (!is_string($workStart) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $workStart) || !is_string($workEnd) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $workEnd) || $workStart >= $workEnd) {
            throw new CalendarArgumentException('work_start and work_end must be valid increasing HH:MM times.', 'work_start', 'invalid range');
        }
        $workdays = $arguments['workdays'] ?? [1,2,3,4,5];
        if (!is_array($workdays) || !array_is_list($workdays) || $workdays === [] || count($workdays) > 7 || array_filter($workdays, static fn($d): bool => !is_int($d) || $d < 1 || $d > 7) !== [] || count($workdays) !== count(array_unique($workdays))) {
            throw new CalendarArgumentException('workdays must be unique ISO weekdays from 1 to 7.', 'workdays', 'invalid');
        }
        $requiredUids = $this->uidList($arguments['required_attendees'] ?? [], 'required_attendees');
        $optionalUids = $this->uidList($arguments['optional_attendees'] ?? [], 'optional_attendees');
        if (array_intersect($requiredUids, $optionalUids) !== []) throw new CalendarArgumentException('An attendee cannot be both required and optional.', 'optional_attendees', 'duplicate');
        if (count($requiredUids) + count($optionalUids) > self::MAX_ATTENDEES) throw new CalendarArgumentException('At most 50 attendees are allowed.', 'required_attendees', 'too many');
        $limit = $arguments['limit'] ?? 5;
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_RESULTS) throw new CalendarArgumentException('limit must be between 1 and 20.', 'limit', 'out of range');
        $required = $this->attendees->resolve($requiredUids, $organizerUid);
        $optional = $this->attendees->resolve($optionalUids, $organizerUid);
        $resolved = [...$required, ...$optional];
        $own = $this->organizerAvailability->execute(['from'=>$from->format('Y-m-d\TH:i:s\Z'),'to'=>$to->format('Y-m-d\TH:i:s\Z'),'min_duration_minutes'=>5], $organizerUid);
        if (($own['isError'] ?? false) === true) return CalendarResult::error('Organizer availability could not be verified.');
        $ownData = json_decode($own['content'][0]['text'] ?? '', true);
        if (!is_array($ownData) || !is_array($ownData['busy'] ?? null)) return CalendarResult::error('Organizer availability could not be verified.');
        $busy = array_map(static fn(array $entry): array => [new DateTimeImmutable($entry['start']), new DateTimeImmutable($entry['end'])], $ownData['busy']);
        $slots = []; $checks = 0; $truncated = false;
        $localFrom = $from->setTimezone($zone); $localTo = $to->setTimezone($zone);
        for ($day = $localFrom->setTime(0,0); $day < $localTo; $day = $day->modify('+1 day')) {
            if (!in_array((int)$day->format('N'), $workdays, true)) continue;
            $cursor = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $workStart, $zone);
            $close = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $workEnd, $zone);
            while ($cursor->modify('+' . $duration . ' minutes') <= $close) {
                $end = $cursor->modify('+' . $duration . ' minutes');
                if ($cursor >= $localFrom && $end <= $localTo && !$this->overlaps($cursor, $end, $busy)) {
                    if ($checks >= self::MAX_CHECKS) { $truncated = true; break 2; }
                    ++$checks;
                    $verdict = $this->attendeeAvailability->check($organizerUid, $cursor, $end, $resolved);
                    $requiredStatus = $this->statuses($required, $verdict);
                    if (!in_array('busy', array_column($requiredStatus, 'status'), true)) {
                        $optionalStatus = $this->statuses($optional, $verdict);
                        $slots[] = ['start'=>$cursor->format('Y-m-d\TH:i:sP'),'end'=>$end->format('Y-m-d\TH:i:sP'),
                            'availability'=>in_array('unknown', array_column($requiredStatus, 'status'), true) ? 'unknown' : 'available',
                            'requiredAttendees'=>$requiredStatus,'optionalAttendees'=>$optionalStatus];
                        if (count($slots) >= $limit) { $truncated = true; break 2; }
                    }
                }
                $cursor = $cursor->modify('+30 minutes');
            }
        }
        return CalendarResult::json(['slots'=>$slots,'checks'=>$checks,'truncated'=>$truncated,'organizerAvailability'=>'verified']);
    }
    private function uidList(mixed $uids, string $field): array
    {
        if (!is_array($uids) || !array_is_list($uids) || count($uids) > self::MAX_ATTENDEES || array_filter($uids, static fn($uid): bool => !is_string($uid) || $uid === '') !== [] || count($uids) !== count(array_unique($uids))) {
            throw new CalendarArgumentException('Attendees must be a list of unique account IDs (maximum 50).', $field, 'invalid');
        }
        return $uids;
    }
    private function overlaps(DateTimeImmutable $start, DateTimeImmutable $end, array $busy): bool
    {
        foreach ($busy as [$a,$b]) if ($a < $end && $b > $start) return true;
        return false;
    }
    private function statuses(array $attendees, array $verdict): array
    {
        $busy = array_column($verdict['busy'], null, 'uid'); $unknown = array_column($verdict['unverifiable'], null, 'uid');
        return array_map(static function(array $a) use ($busy,$unknown,$verdict): array {
            $status = ($verdict['failed'] ?? false) || isset($unknown[$a['uid']]) ? 'unknown' : (isset($busy[$a['uid']]) ? 'busy' : 'available');
            return ['uid'=>$a['uid'],'displayName'=>$a['displayName'],'status'=>$status];
        }, $attendees);
    }
}
