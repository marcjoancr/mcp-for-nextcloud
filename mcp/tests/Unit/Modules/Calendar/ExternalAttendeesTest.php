<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use OCA\Mcp\Tests\Support\Calendar\CalendarTestCase;
use Sabre\VObject\Reader;

final class ExternalAttendeesTest extends CalendarTestCase {
    public function testCreateDeduplicatesExplicitAddressesAgainstResolvedAccounts(): void {
        $args = [
            'calendar' => self::PERSONAL, 'summary' => 'Review',
            'start' => '2026-03-12T09:00:00Z', 'end' => '2026-03-12T10:00:00Z',
            'attendees' => ['bob'], 'external_attendees' => ['BOB@EXAMPLE.INVALID', 'vendor@example.test'],
        ];
        $this->writeHandlers['calendar_create_event']->execute($args, 'alice');
        $written = Reader::read($this->dav->calls[0][1][2])->VEVENT;
        self::assertSame(
            ['mailto:bob@example.invalid', 'mailto:vendor@example.test'],
            array_map('strval', array_values($written->select('ATTENDEE'))),
        );
        self::assertFalse($this->dav->calls[0][1][3]);
    }

    public function testEditKeepsExistingAttendeeParametersAndOnlyRemovesSelectedAddress(): void {
        $this->store->addObject(1, 'e.ics', self::ics("UID:e\nSUMMARY:Review\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z\n"
            . "ORGANIZER:mailto:alice@example.invalid\nATTENDEE;CN=Bob;PARTSTAT=ACCEPTED:mailto:bob@example.invalid\n"
            . "ATTENDEE;CN=Vendor:mailto:vendor@example.test"));
        $args = [
            'calendar' => self::PERSONAL, 'uid' => 'e', 'add_attendee_emails' => ['NEW@example.test'],
            'remove_attendee_emails' => ['VENDOR@example.test'], 'send_invitations' => true,
        ];
        $prepared = $this->writeHandlers['calendar_update_event']->prepare($args, 'alice');
        $this->assertNoWrites();
        $prepared->dispatch();
        $written = Reader::read($this->dav->calls[0][1][3])->VEVENT;
        $attendees = array_values($written->select('ATTENDEE'));
        self::assertSame(['mailto:bob@example.invalid', 'mailto:NEW@example.test'], array_map('strval', $attendees));
        self::assertSame('ACCEPTED', (string)$attendees[0]['PARTSTAT']);
        self::assertTrue($this->dav->calls[0][1][4]);
    }

    public function testNativeExternalAttendeeSettingAlsoAppliesToMcp(): void {
        $this->externalAttendeesDisabled = true;
        $args = [
            'calendar' => self::PERSONAL, 'summary' => 'Review',
            'start' => '2026-03-12T09:00:00Z', 'end' => '2026-03-12T10:00:00Z',
            'external_attendees' => ['vendor@example.test'],
        ];
        foreach ([[], ['confirm' => true]] as $confirmation) {
            $result = $this->registry->call('calendar_create_event_with_external_attendees', $args + $confirmation, 'alice');
            self::assertTrue($result['isError']);
        }
        $this->assertNoWrites();
    }

    public function testRejectsAddressThatCouldChangeTheMailtoUri(): void {
        $this->expectException(CalendarArgumentException::class);
        try {
            $this->writeHandlers['calendar_create_event']->execute([
                'calendar' => self::PERSONAL, 'summary' => 'Review',
                'start' => '2026-03-12T09:00:00Z', 'end' => '2026-03-12T10:00:00Z',
                'external_attendees' => ['victim?bcc=other@example.test'],
            ], 'alice');
        } finally {
            $this->assertNoWrites();
        }
    }
}
