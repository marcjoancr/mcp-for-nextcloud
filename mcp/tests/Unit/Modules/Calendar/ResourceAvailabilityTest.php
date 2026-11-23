<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use DateTimeImmutable;
use OCA\Mcp\Modules\Calendar\Resources\ResourceAvailability;
use OCA\Mcp\Modules\Calendar\Scheduling\AttendeeResolver;
use OCA\Mcp\Platform\Dav\CalDav\EmbeddedCalDavDispatcher;
use OCA\Mcp\Platform\Dav\Transport\DavResult;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

final class ResourceAvailabilityTest extends TestCase {
    public static function results(): array {
        return [
            'free' => ['2.0;Success', '', 'free'],
            'busy' => ['2.0;Success', "FREEBUSY;FBTYPE=BUSY:20261128T100000Z/20261128T103000Z\r\n", 'busy'],
            'explicit free' => ['2.0;Success', "FREEBUSY;FBTYPE=FREE:20261128T100000Z/20261128T110000Z\r\n", 'free'],
            'wrong response recipient' => ['2.0;Success', '', 'unknown', 'other@example.test'],
            'access denied' => ['3.8;No authority', '', 'unknown'],
            'malformed calendar data' => ['2.0;Success', '', 'unknown', 'room@example.test', 'corrupt'],
        ];
    }

    #[DataProvider('results')]
    public function testSummarizesNativeResultWithoutReturningEvents(string $status, string $freebusy, string $expected, string $email = 'room@example.test', ?string $override = null): void {
        $user = $this->createMock(IUser::class);
        $user->method('isEnabled')->willReturn(true);
        $user->method('getEMailAddress')->willReturn('alice@example.test');
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->with('alice')->willReturn($user);
        $dav = $this->createMock(EmbeddedCalDavDispatcher::class);
        $dav->expects(self::once())->method('resourceFreeBusy')->with('alice', self::isType('string'))
            ->willReturnCallback(static function (string $actor, string $body) use ($status, $freebusy, $email, $override): DavResult {
                $request = Reader::read($body);
                self::assertSame('REQUEST', (string)$request->METHOD);
                self::assertSame('mailto:alice@example.test', (string)$request->VFREEBUSY->ORGANIZER);
                self::assertSame('mailto:room@example.test', (string)$request->VFREEBUSY->ATTENDEE);
                $uid = (string)$request->VFREEBUSY->UID;
                $ical = $override ?? "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:REPLY\r\nBEGIN:VFREEBUSY\r\nUID:$uid\r\n"
                    . "DTSTART:20261128T100000Z\r\nDTEND:20261128T110000Z\r\nATTENDEE:mailto:$email\r\n"
                    . $freebusy . "END:VFREEBUSY\r\nEND:VCALENDAR\r\n";
                return new DavResult(200, null, '<cal:schedule-response xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">'
                    . "<cal:response><cal:recipient><d:href>mailto:$email</d:href></cal:recipient><cal:request-status>$status</cal:request-status>"
                    . '<cal:calendar-data>' . htmlspecialchars($ical, ENT_XML1) . '</cal:calendar-data></cal:response></cal:schedule-response>');
            });
        $service = new ResourceAvailability($dav, new AttendeeResolver($users));
        self::assertSame($expected, $service->status('alice', 'room@example.test',
            new DateTimeImmutable('2026-11-28T10:00:00Z'), new DateTimeImmutable('2026-11-28T11:00:00Z')));
    }
}
