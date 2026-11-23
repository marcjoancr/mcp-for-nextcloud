<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Calendar;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\OAuth\OAuthAccountTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Server;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\VObject\Reader;

final class InvitationReplyTest extends OAuthAccountTestCase {
    public function testMcpRespondsAndUpdatesTheOrganizersCopyThroughNativeCalDav(): void {
        $backend = Server::get(CalDavBackend::class);
        $config = Server::get(IAppConfig::class);
        $enabled = $config->hasKey('dav', 'sendInvitations')
            ? $config->getValueString('dav', 'sendInvitations') : null;
        $organizerId = 'mcp-it-organizer-' . bin2hex(random_bytes(6));
        $organizer = Server::get(IUserManager::class)->createUser($organizerId, bin2hex(random_bytes(24)));
        self::assertNotFalse($organizer);
        try {
            $config->setValueString('dav', 'sendInvitations', 'no');
            $organizer->setEMailAddress($organizerId . '@example.test');
            $invited = $this->uid . '@example.test';
            $this->user->setEMailAddress($invited);
            self::assertSame($invited, $this->user->getSystemEMailAddress(), 'The actor must have a DAV calendar address.');
            self::assertSame($organizerId . '@example.test', $organizer->getSystemEMailAddress(), 'The organizer must have a DAV calendar address.');
            $properties = ['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT'])];
            $inviteeCalendar = $backend->createCalendar('principals/users/' . $this->uid, 'personal', $properties);
            $organizerCalendar = $backend->createCalendar('principals/users/' . $organizerId, 'personal', $properties);
            $start = new \DateTimeImmutable('tomorrow 10:00:00', new \DateTimeZone('UTC'));
            $dtstart = $start->format('Ymd\THis\Z');
            $dtend = $start->modify('+1 hour')->format('Ymd\THis\Z');
            $now = gmdate('Ymd\THis\Z');
            $vcalendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//MCP//Calendar test//EN\r\nBEGIN:VEVENT\r\n"
                . "UID:mcp-reply\r\nSUMMARY:MCP reply\r\nDTSTAMP:$now\r\nDTSTART:$dtstart\r\nDTEND:$dtend\r\n"
                . "ORGANIZER:mailto:$organizerId@example.test\r\nATTENDEE;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:$invited\r\n"
                . "END:VEVENT\r\nEND:VCALENDAR\r\n";
            $backend->createCalendarObject($inviteeCalendar, 'invitation.ics', $vcalendar);
            $backend->createCalendarObject($organizerCalendar, 'invitation.ics', $vcalendar);

            Server::get(GrantPolicy::class)->setConnected($this->uid, true);
            Server::get(PolicyTransaction::class)->runInternal([$this->uid], static fn () => null, function (): bool {
                Server::get(ToolGrantsMapper::class)->set(ToolGrantsMapper::USER, $this->uid, 'calendar_respond_to_invitation', 'allow');
                Server::get(ToolGrantsMapper::class)->set(ToolGrantsMapper::USER, $this->uid, 'calendar_search_events', 'allow');
                return true;
            });
            $client = new McpClient(LoginFactory::basic($this->uid));
            $args = [
                'calendar' => '/remote.php/dav/calendars/' . $this->uid . '/personal/',
                'uid' => 'mcp-reply',
                'response' => 'accepted',
            ];
            $search = [
                'calendar' => $args['calendar'], 'from' => $start->format('Y-m-d'),
                'to' => $start->modify('+1 day')->format('Y-m-d'), 'participation_status' => 'needs-action',
            ];
            $pending = $client->tool('calendar_search_events', $search);
            self::assertTrue($pending->ok(), $pending->describe());
            self::assertSame(['mcp-reply'], array_column($pending->json()['items'], 'uid'));
            $plan = $client->tool('calendar_respond_to_invitation', $args);
            self::assertTrue($plan->ok(), $plan->describe());
            self::assertSame('ACCEPTED', $plan->structured()['after']);
            $result = $client->write('calendar_respond_to_invitation', $args + ['etag' => $plan->structured()['etag']]);
            self::assertTrue($result->ok(), $result->describe());
            self::assertSame('ACCEPTED', $result->json()['response']);
            $answered = $client->tool('calendar_search_events', $search);
            self::assertTrue($answered->ok(), $answered->describe());
            self::assertSame([], $answered->json()['items']);
            self::assertSame('1.2', $result->json()['scheduling']['scheduleStatus'], json_encode($result->json()['scheduling']));
            // The HTTP request has its own backend; the single-object getter keeps a per-request cache.
            [$copy] = $backend->getMultipleCalendarObjects($organizerCalendar, ['invitation.ics']);
            self::assertSame('ACCEPTED', (string)Reader::read($copy['calendardata'])->VEVENT->ATTENDEE['PARTSTAT']);
        } finally {
            $organizer->delete();
            if ($enabled === null) {
                $config->deleteKey('dav', 'sendInvitations');
            } else {
                $config->setValueString('dav', 'sendInvitations', $enabled);
            }
        }
    }
}
