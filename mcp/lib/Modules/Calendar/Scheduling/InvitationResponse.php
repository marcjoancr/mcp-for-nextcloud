<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Scheduling;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Events\StoredEvent;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Platform\Dav\CalDav\CalDavWriter;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess;
use OCA\Mcp\Platform\Dav\Collections\Collection;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;

final class InvitationResponse {
    public const RESPONSES = ['accepted', 'declined', 'tentative'];

    public function __construct(
        private ScopedCollectionAccess $access,
        private EventRepository $events,
        private ObjectStore $store,
        private CalDavWriter $writer,
        private AttendeeResolver $accounts,
        private Scheduling $scheduling,
    ) {}

    /** @param array<string,mixed> $arguments */
    public function preview(array $arguments, string $userId): array {
        [$calendar, $stored, $event, $attendee, $response] = $this->prepare($arguments, $userId);
        $before = $this->status($attendee);
        return [
            'action' => 'calendar_respond_to_invitation',
            'calendar' => $calendar->path,
            'uid' => (string)$event->UID,
            'summary' => (string)($event->SUMMARY ?? ''),
            'organizer' => (string)$event->ORGANIZER,
            'etag' => $stored->etag,
            'before' => $before,
            'after' => $response,
            'willReply' => $response !== $before,
            'imipEnabled' => $this->scheduling->imipEnabled(),
        ];
    }

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments, string $userId): array {
        if (!isset($arguments['etag'])) {
            throw new CalendarArgumentException(Translator::t('Provide the ETag from the event preview.'), 'etag');
        }
        [$calendar, $stored, $event, $attendee, $response] = $this->prepare($arguments, $userId);
        $changed = $response !== $this->status($attendee);
        $etag = $stored->etag;
        $scheduleStatus = null;
        if ($changed) {
            $attendee['PARTSTAT'] = $response;
            // The user's own CalDAV copy allows Sabre to generate REPLY from the PARTSTAT difference.
            $this->writer->update($userId, $calendar->uri, $stored->uri, $etag, $stored->vcalendar->serialize(), true);
            $row = $this->store->object($calendar->id, $stored->uri);
            $written = $row === null ? null : $this->events->parse($row['data']);
            if ($row === null || $written === null || $row['deleted']) {
                throw CalendarException::blocked(Translator::t('The changed invitation could not be verified.'));
            }
            $writtenEvent = (new StoredEvent($row['id'], $row['uri'], $row['etag'], $written))->master();
            if ($writtenEvent === null || $this->status($this->ownAttendee($writtenEvent, (string)$attendee)) !== $response) {
                throw CalendarException::blocked(Translator::t('The changed invitation could not be verified.'));
            }
            $etag = $row['etag'];
            $scheduleStatus = isset($writtenEvent->ORGANIZER['SCHEDULE-STATUS'])
                ? (string)$writtenEvent->ORGANIZER['SCHEDULE-STATUS']
                : null;
        }
        return ToolResult::json([
            'calendar' => $calendar->path,
            'uid' => (string)$event->UID,
            'etag' => $etag,
            'response' => $response,
            'changed' => $changed,
            'scheduling' => [
                'requested' => $changed,
                'scheduleStatus' => $scheduleStatus,
                'imipEnabled' => $this->scheduling->imipEnabled(),
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array{Collection, StoredEvent, VEvent, Property, string}
     */
    private function prepare(array $arguments, string $userId): array {
        if (!in_array($arguments['response'] ?? null, self::RESPONSES, true)) {
            throw new CalendarArgumentException(Translator::t('Choose accepted, declined, or tentative.'), 'response');
        }
        $calendar = $this->access->resolveWritable($userId, $arguments['calendar']);
        if ($calendar->ownerId !== $userId) {
            throw CalendarException::forbidden();
        }
        $stored = $this->events->forChange($calendar, $arguments['uid'], $userId, $arguments['etag'] ?? null);
        $event = $stored->master() ?? throw CalendarException::notFound();
        $email = 'mailto:' . $this->accounts->organizer($userId)['email'];
        $organizer = (string)($event->ORGANIZER ?? '');
        $agent = strtoupper((string)($event->ORGANIZER['SCHEDULE-AGENT'] ?? 'SERVER'));
        if (!str_starts_with(strtolower($organizer), 'mailto:') || strcasecmp($organizer, $email) === 0
            || $agent !== 'SERVER' || strtoupper((string)($event->STATUS ?? '')) === 'CANCELLED') {
            throw CalendarException::blocked(Translator::t('This event cannot be answered as a calendar invitation.'));
        }
        return [$calendar, $stored, $event, $this->ownAttendee($event, $email), strtoupper($arguments['response'])];
    }

    private function ownAttendee(VEvent $event, string $email): Property {
        $matching = array_values(array_filter($event->select('ATTENDEE'), static fn (Property $attendee): bool =>
            strcasecmp((string)$attendee, $email) === 0));
        if (count($matching) !== 1) {
            throw CalendarException::blocked(Translator::t('This event cannot be answered as a calendar invitation.'));
        }
        return $matching[0];
    }

    private function status(Property $attendee): string {
        return strtoupper((string)($attendee['PARTSTAT'] ?? 'NEEDS-ACTION'));
    }
}
