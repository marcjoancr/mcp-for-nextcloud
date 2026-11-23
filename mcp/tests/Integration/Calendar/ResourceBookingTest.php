<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Tests\Integration\OAuth\OAuthAccountTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\IAppConfig;
use OCP\Server;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\VObject\Component\VCalendar;

final class ResourceBookingTest extends OAuthAccountTestCase {
    public function testNativeAvailabilityAndTwoBookingsCompetingForOneRoom(): void {
        $backend = Server::get(CalDavBackend::class);
        $config = Server::get(IAppConfig::class);
        $imip = $config->hasKey('dav', 'sendInvitations') ? $config->getValueString('dav', 'sendInvitations') : null;
        $room = 'fixture' . bin2hex(random_bytes(8));
        $name = 'MCP meeting room ' . $room;
        $principal = 'principals/calendar-rooms/mcpfixture-' . $room;
        $query = $this->official()->getQueryBuilder();
        $query->insert('calendar_rooms')->values([
            'backend_id' => $query->createNamedParameter('mcpfixture'),
            'resource_id' => $query->createNamedParameter($room),
            'email' => $query->createNamedParameter($room . '@example.test'),
            'displayname' => $query->createNamedParameter($name),
        ])->executeStatement();
        $roomCalendar = null;
        try {
            $this->user->setEMailAddress($this->uid . '@example.test');
            $config->setValueString('dav', 'sendInvitations', 'no');
            $components = ['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT'])];
            $roomCalendar = $backend->createCalendar($principal, CalDavBackend::RESOURCE_BOOKING_CALENDAR_URI, $components);
            $calendar = $backend->createCalendar('principals/users/' . $this->uid, 'meeting-fixture', $components);
            $start = new DateTimeImmutable('tomorrow 10:00', new DateTimeZone('UTC'));
            $end = $start->modify('+1 hour');
            foreach (['first', 'second'] as $uid) {
                $ical = new VCalendar();
                $ical->add('VEVENT', ['UID' => $uid, 'DTSTART' => $start, 'DTEND' => $end, 'SUMMARY' => 'Room booking fixture']);
                $backend->createCalendarObject($calendar, $uid . '.ics', $ical->serialize());
            }

            Server::get(GrantPolicy::class)->setConnected($this->uid, true);
            Server::get(PolicyTransaction::class)->runInternal([$this->uid], static fn () => null, function (): bool {
                $grants = Server::get(ToolGrantsMapper::class);
                foreach (['calendar_list_resources', 'calendar_check_resource_availability', 'calendar_reserve_resource', 'calendar_delete_event'] as $tool) {
                    $grants->set(ToolGrantsMapper::USER, $this->uid, $tool, 'allow');
                }
                return true;
            });
            $client = new McpClient(LoginFactory::basic($this->uid));
            $listed = $client->tool('calendar_list_resources', ['type' => 'ROOM', 'query' => $name]);
            self::assertTrue($listed->ok(), $listed->describe());
            self::assertSame([$principal], array_column($listed->json()['resources'], 'id'));
            $roomArgs = ['type' => 'ROOM', 'resource' => $principal];
            $window = $roomArgs + ['start' => $start->format(DATE_ATOM), 'end' => $end->format(DATE_ATOM)];
            $free = $client->tool('calendar_check_resource_availability', $window);
            self::assertTrue($free->ok(), $free->describe());
            self::assertSame('free', $free->json()['availability'], $free->describe());

            $calendarArgs = $roomArgs + ['calendar' => '/remote.php/dav/calendars/' . $this->uid . '/meeting-fixture/'];
            foreach (['first' => 'ACCEPTED', 'second' => 'DECLINED'] as $uid => $expected) {
                $args = $calendarArgs + ['uid' => $uid];
                $preview = $client->tool('calendar_reserve_resource', $args);
                self::assertTrue($preview->ok(), $preview->describe());
                $modified = $client->write('calendar_reserve_resource', $args + ['etag' => $preview->structured()['etag']]);
                self::assertTrue($modified->ok(), $modified->describe());
                self::assertSame($expected, $modified->json()['resourceBooking']['status'], $modified->describe());
            }
            $busy = $client->tool('calendar_check_resource_availability', $window);
            self::assertTrue($busy->ok(), $busy->describe());
            self::assertSame('busy', $busy->json()['availability'], $busy->describe());

            foreach (['second', 'first'] as $uid) {
                $args = ['uid' => $uid, 'calendar' => $calendarArgs['calendar'], 'send_invitations' => true];
                $plan = $client->tool('calendar_delete_event', $args);
                self::assertTrue($plan->ok(), $plan->describe());
                $cancelled = $client->write('calendar_delete_event', $args + ['etag' => $plan->structured()['etag']]);
                self::assertTrue($cancelled->ok(), $cancelled->describe());
            }
            $released = $client->tool('calendar_check_resource_availability', $window);
            self::assertTrue($released->ok(), $released->describe());
            self::assertSame('free', $released->json()['availability'], $released->describe());
        } finally {
            if ($roomCalendar !== null) {
                $backend->deleteCalendar($roomCalendar, true);
            }
            $cleanup = $this->official()->getQueryBuilder();
            $cleanup->delete('calendar_rooms')->where($cleanup->expr()->eq('resource_id', $cleanup->createNamedParameter($room)))->executeStatement();
            if ($imip === null) {
                $config->deleteKey('dav', 'sendInvitations');
            } else {
                $config->setValueString('dav', 'sendInvitations', $imip);
            }
        }
    }
}
