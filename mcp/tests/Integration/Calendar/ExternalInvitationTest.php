<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Calendar;

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
use Sabre\VObject\Reader;

final class ExternalInvitationTest extends OAuthAccountTestCase {
    public function testExternalAddressIsPreviewedAndHandedToNextcloudScheduling(): void {
        $config = Server::get(IAppConfig::class);
        $enabled = $config->hasKey('dav', 'sendInvitations') ? $config->getValueString('dav', 'sendInvitations') : null;
        $backend = Server::get(CalDavBackend::class);
        $this->user->setEMailAddress($this->uid . '@example.test');
        $calendar = $backend->createCalendar('principals/users/' . $this->uid, 'external-fixture', [
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT']),
        ]);
        try {
            // Exercise native scheduling with the mail transport disabled in this disposable instance.
            $config->setValueString('dav', 'sendInvitations', 'no');
            Server::get(GrantPolicy::class)->setConnected($this->uid, true);
            Server::get(PolicyTransaction::class)->runInternal([$this->uid], static fn () => null, function (): bool {
                $grants = Server::get(ToolGrantsMapper::class);
                $grants->set(ToolGrantsMapper::USER, $this->uid, 'calendar_create_event_with_external_attendees', 'allow');
                $grants->set(ToolGrantsMapper::USER, $this->uid, 'calendar_update_event_with_external_attendees', 'allow');
                return true;
            });
            $client = new McpClient(LoginFactory::basic($this->uid));
            $path = '/remote.php/dav/calendars/' . $this->uid . '/external-fixture/';
            $address = 'outside@example.test';
            $next = (new \DateTimeImmutable('tomorrow 10:00', new \DateTimeZone('UTC')));
            $arguments = [
                'calendar' => $path,
                'summary' => 'External invitation',
                'start' => $next->format(DATE_ATOM),
                'end' => $next->modify('+1 hour')->format(DATE_ATOM),
                'external_attendees' => [$address],
                'send_invitations' => true,
            ];
            $plan = $client->tool('calendar_create_event_with_external_attendees', $arguments);
            self::assertTrue($plan->ok(), $plan->describe());
            self::assertStringContainsString($address, $plan->text());
            self::assertFalse($plan->structured()['scheduling']['imipEnabled']);
            self::assertSame([], $backend->getCalendarObjects($calendar));

            $written = $client->write('calendar_create_event_with_external_attendees', $arguments);
            self::assertTrue($written->ok(), $written->describe());
            $result = $written->json();
            self::assertTrue($result['scheduling']['requested']);
            self::assertFalse($result['scheduling']['imipEnabled']);
            $uid = $result['uid'];
            [$stored] = $backend->getMultipleCalendarObjects($calendar, [$uid . '.ics']);
            self::assertSame('mailto:' . $address, (string)Reader::read($stored['calendardata'])->VEVENT->ATTENDEE);

            $args = ['calendar' => $path, 'uid' => $uid, 'remove_attendee_emails' => [$address], 'send_invitations' => true];
            $edit = $client->tool('calendar_update_event_with_external_attendees', $args);
            self::assertTrue($edit->ok(), $edit->describe());
            self::assertStringContainsString($address, $edit->text());
            $update = $client->write('calendar_update_event_with_external_attendees', $args + ['etag' => $edit->structured()['etag']]);
            self::assertTrue($update->ok(), $update->describe());
            [$stored] = $backend->getMultipleCalendarObjects($calendar, [$uid . '.ics']);
            self::assertSame([], Reader::read($stored['calendardata'])->VEVENT->select('ATTENDEE'));
        } finally {
            if ($enabled === null) {
                $config->deleteKey('dav', 'sendInvitations');
            } else {
                $config->setValueString('dav', 'sendInvitations', $enabled);
            }
        }
    }
}
