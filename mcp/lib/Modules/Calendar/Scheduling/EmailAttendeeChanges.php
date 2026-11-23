<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Scheduling;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use Sabre\VObject\Component\VEvent;

final class EmailAttendeeChanges {
    /**
     * @param list<string> $add
     * @param list<string> $remove
     */
    public static function apply(VEvent $event, array $add, array $remove, string $organizerEmail, string $organizerName): void {
        $add = self::addresses($add, 'add_attendee_emails');
        $remove = self::addresses($remove, 'remove_attendee_emails');
        $organizer = 'mailto:' . strtolower($organizerEmail);
        if (isset($add[$organizer]) || isset($remove[$organizer]) || array_intersect_key($add, $remove) !== []) {
            throw new CalendarArgumentException(Translator::t('Attendee lists overlap or contain the organizer.'));
        }

        $retained = [];
        foreach ($event->select('ATTENDEE') as $attendee) {
            $address = strtolower((string)$attendee);
            if (isset($remove[$address])) {
                $event->remove($attendee);
                continue;
            }
            $retained[$address] = true;
        }
        $new = array_diff_key($add, $retained);
        if (count($event->select('ATTENDEE')) + count($new) > AttendeeResolver::MAX_ATTENDEES) {
            throw new CalendarArgumentException(SchedulingMessages::attendeesInvalid());
        }
        if ($new !== [] && !isset($event->ORGANIZER)) {
            $event->add('ORGANIZER', 'mailto:' . $organizerEmail, ['CN' => $organizerName]);
        }
        foreach ($new as $email) {
            $event->add('ATTENDEE', 'mailto:' . $email, [
                'CN' => $email,
                'CUTYPE' => 'INDIVIDUAL',
                'ROLE' => 'REQ-PARTICIPANT',
                'PARTSTAT' => 'NEEDS-ACTION',
                'RSVP' => 'TRUE',
            ]);
        }
    }

    /**
     * @param list<string> $input
     * @return array<string, string>
     */
    private static function addresses(array $input, string $field): array {
        if (count($input) > AttendeeResolver::MAX_ATTENDEES) {
            throw new CalendarArgumentException(SchedulingMessages::attendeesInvalid());
        }
        $addresses = [];
        foreach ($input as $email) {
            // A bare, unambiguous mailto address. URI escapes and query delimiters are refused.
            if (!is_string($email) || strlen($email) > 254
                || preg_match('/^[A-Za-z0-9.!\x24&\x27*+_=^{}|~-]+@[A-Za-z0-9.-]+$/D', $email) !== 1
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new CalendarArgumentException(Translator::t('Enter a valid attendee email address.'), $field);
            }
            $addresses['mailto:' . strtolower($email)] ??= $email;
        }
        return $addresses;
    }
}
