<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Resources;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMXPath;
use OCA\Mcp\Modules\Calendar\Scheduling\AttendeeResolver;
use OCA\Mcp\Platform\Dav\CalDav\EmbeddedCalDavDispatcher;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

final class ResourceAvailability {
    private const CALDAV = 'urn:ietf:params:xml:ns:caldav';

    public function __construct(private EmbeddedCalDavDispatcher $dav, private AttendeeResolver $accounts) {}

    public function status(string $actorId, string $address, DateTimeImmutable $from, DateTimeImmutable $to): string {
        if ($address === '') {
            return 'unknown';
        }
        try {
            $recipient = 'mailto:' . $address;
            $uid = bin2hex(random_bytes(16));
            $utc = new DateTimeZone('UTC');
            $request = new VCalendar(['METHOD' => 'REQUEST']);
            $request->add('VFREEBUSY', [
                'UID' => $uid,
                'DTSTAMP' => new DateTimeImmutable('now', $utc),
                'DTSTART' => $from->setTimezone($utc),
                'DTEND' => $to->setTimezone($utc),
                'ORGANIZER' => 'mailto:' . $this->accounts->organizer($actorId)['email'],
                'ATTENDEE' => $recipient,
            ]);
            $response = $this->dav->resourceFreeBusy($actorId, $request->serialize());
            return $response->body === null ? 'unknown' : $this->readStatus($response->body, $recipient, $uid, $from, $to);
        } catch (\Exception) {
            return 'unknown';
        }
    }

    private function readStatus(string $body, string $recipient, string $uid, DateTimeImmutable $from, DateTimeImmutable $to): string {
        $document = new DOMDocument();
        if (!$document->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) || $document->doctype !== null) {
            return 'unknown';
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('cal', self::CALDAV);
        $xpath->registerNamespace('dav', 'DAV:');
        $nodes = $xpath->query('/cal:schedule-response/cal:response');
        if ($nodes === false || $nodes->length !== 1) {
            return 'unknown';
        }
        $node = $nodes->item(0);
        $destination = $xpath->evaluate('string(cal:recipient/dav:href)', $node);
        $status = $xpath->evaluate('string(cal:request-status)', $node);
        $data = $xpath->evaluate('string(cal:calendar-data)', $node);
        if (strcasecmp($destination, $recipient) !== 0 || explode(';', $status, 2)[0] !== '2.0' || $data === '') {
            return 'unknown';
        }

        $calendar = Reader::read($data);
        $window = $calendar->VFREEBUSY ?? null;
        if ($window === null || (string)$window->UID !== $uid || strcasecmp((string)$window->ATTENDEE, $recipient) !== 0
            || !isset($window->DTSTART, $window->DTEND) || $window->DTSTART->getDateTime() > $from || $window->DTEND->getDateTime() < $to) {
            return 'unknown';
        }
        foreach ($window->select('FREEBUSY') as $period) {
            if (strtoupper((string)($period['FBTYPE'] ?? 'BUSY')) !== 'FREE') {
                return 'busy';
            }
        }
        return 'free';
    }
}
