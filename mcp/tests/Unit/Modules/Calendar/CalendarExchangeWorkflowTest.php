<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Modules\Calendar\Exchange\IcsBundle;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;

final class CalendarExchangeWorkflowTest extends TestCase {
    public function testImportGroupsRecurrenceAndDropsSchedulingInstructions(): void {
        $calendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Fixture//EN\r\nMETHOD:REQUEST\r\n"
            . "BEGIN:VEVENT\r\nUID:meeting\r\nDTSTAMP:20260101T080000Z\r\nDTSTART:20261201T100000Z\r\n"
            . "RRULE:FREQ=WEEKLY;COUNT=3\r\nATTENDEE:mailto:example@example.invalid\r\n"
            . "ORGANIZER:mailto:owner@example.invalid\r\nX-NC-APPOINTMENT:private-link-token\r\n"
            . "BEGIN:VALARM\r\nACTION:EMAIL\r\nTRIGGER:-PT10M\r\nEND:VALARM\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:meeting\r\nDTSTAMP:20260101T080000Z\r\nDTSTART:20261208T120000Z\r\n"
            . "RECURRENCE-ID:20261208T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $rows = (new IcsBundle())->importable($calendar);
        self::assertCount(1, $rows);
        self::assertSame('meeting', $rows[0]['uid']);
        self::assertTrue($rows[0]['stripped']);
        $data = $rows[0]['calendar']->serialize();
        self::assertSame(2, substr_count($data, 'BEGIN:VEVENT'));
        self::assertStringContainsString('RECURRENCE-ID:20261208T100000Z', $data);
        foreach (['METHOD:', 'ATTENDEE:', 'ORGANIZER:', 'BEGIN:VALARM', 'private-link-token'] as $property) {
            self::assertStringNotContainsString($property, $data);
        }
    }

    public function testRefusesMixedCalendarAndDoesNotSilentlyImportTasks(): void {
        $data = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:task\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
        $this->expectException(ToolFailure::class);
        (new IcsBundle())->importable($data);
    }
}
