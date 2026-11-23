<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
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
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\Server;
use OCP\User\Events\UserFirstTimeLoggedInEvent;
use Sabre\VObject\Reader;

final class CalendarExchangeNativeTest extends OAuthAccountTestCase {
    public function testImportThenSetReminderAndExportTheNativeEvent(): void {
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        $policy->setConnected($this->uid, true);
        $policy->setGrant($this->uid, 'files', 'read', true);
        $tools = ['calendar_import_ics', 'calendar_export_ics', 'calendar_set_event_reminders'];
        Server::get(PolicyTransaction::class)->runInternal([$this->uid], static fn () => null, function () use ($tools): bool {
            foreach ($tools as $tool) {
                Server::get(ToolGrantsMapper::class)->set(ToolGrantsMapper::USER, $this->uid, $tool, ToolGrantsMapper::ALLOW);
            }
            return true;
        });
        Server::get(IEventDispatcher::class)->dispatchTyped(new UserFirstTimeLoggedInEvent($this->user));
        $backend = Server::get(CalDavBackend::class);
        $calendarId = $backend->createCalendar('principals/users/' . $this->uid, 'mcp-ics-test', [
            '{DAV:}displayname' => 'MCP iCalendar exercise',
        ]);
        $calendar = '/remote.php/dav/calendars/' . rawurlencode($this->uid) . '/mcp-ics-test/';
        $contents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Fixture//EN\r\nMETHOD:REQUEST\r\n"
            . "BEGIN:VEVENT\r\nUID:ics-workflow\r\nDTSTAMP:20260101T080000Z\r\nDTSTART:20261201T100000Z\r\n"
            . "DTEND:20261201T103000Z\r\nSUMMARY:Calendar workflow\r\n"
            . "ORGANIZER:mailto:organizer@example.test\r\nATTENDEE:mailto:guest@example.test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        try {
            Server::get(IRootFolder::class)->getUserFolder($this->uid)->newFile('calendar-workflow.ics', $contents);
            $client = new McpClient(LoginFactory::basic($this->uid));
            $args = ['path' => '/calendar-workflow.ics', 'calendar' => $calendar];
            $preview = $client->tool('calendar_import_ics', $args);
            self::assertTrue($preview->ok(), $preview->describe());
            self::assertSame('create', $preview->structured()['events'][0]['status']);
            self::assertTrue($preview->structured()['events'][0]['excluded_scheduling_or_attachment_data']);

            $import = $client->write('calendar_import_ics', $args + ['plan_state' => $preview->structured()['plan_state']]);
            self::assertTrue($import->ok(), $import->describe());
            self::assertSame('created', $import->json()['events'][0]['status'], $import->describe());
            $duplicate = $client->tool('calendar_import_ics', $args);
            self::assertTrue($duplicate->ok(), $duplicate->describe());
            self::assertSame('already_present', $duplicate->structured()['events'][0]['status']);

            $reminders = ['calendar' => $calendar, 'uid' => 'ics-workflow', 'minutes_before' => [15]];
            $reminderPlan = $client->tool('calendar_set_event_reminders', $reminders);
            self::assertTrue($reminderPlan->ok(), $reminderPlan->describe());
            $reminderWrite = $client->write('calendar_set_event_reminders', $reminders + ['plan_state' => $reminderPlan->structured()['plan_state']]);
            self::assertTrue($reminderWrite->ok(), $reminderWrite->describe());
            self::assertSame([15], $reminderWrite->json()['minutes_before']);

            $export = $client->tool('calendar_export_ics', ['calendar' => $calendar, 'uids' => ['ics-workflow']]);
            self::assertTrue($export->ok(), $export->describe());
            $event = Reader::read($export->json()['icalendar'])->VEVENT;
            self::assertSame('Calendar workflow', (string)$event->SUMMARY);
            self::assertSame('-PT15M', (string)$event->VALARM->TRIGGER);
            self::assertFalse(isset($event->ATTENDEE, $event->ORGANIZER));
        } finally {
            $backend->deleteCalendar((int)$calendarId, true);
        }
    }
}
