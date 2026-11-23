<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventDates;
use OCA\Mcp\Modules\Calendar\Events\EventExpander;
use OCA\Mcp\Modules\Calendar\Events\EventMapper;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Reading\EventSearch;
use OCA\Mcp\Tests\Support\Calendar\CalendarTestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\NullLogger;

final class ParticipationFilterTest extends CalendarTestCase
{
    public function testPendingFilterUsesOnlyActorsOwnAttendeePartstat(): void
    {
        $this->store->addObject(1, 'own-pending.ics', self::ics("UID:own-pending\nSUMMARY:Invitation\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z\nATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:alice@example.invalid\nATTENDEE;PARTSTAT=ACCEPTED:mailto:bob@example.invalid"));
        $this->store->addObject(1, 'other-pending.ics', self::ics("UID:other-pending\nSUMMARY:Invitation\nDTSTART:20260311T110000Z\nDTEND:20260311T120000Z\nATTENDEE;PARTSTAT=ACCEPTED:mailto:alice@example.invalid\nATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:bob@example.invalid"));
        $this->store->addObject(1, 'not-invited.ics', self::ics("UID:not-invited\nSUMMARY:Invitation\nDTSTART:20260311T130000Z\nDTEND:20260311T140000Z\nATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:bob@example.invalid"));
        $this->store->addObject(1, 'organizer.ics', self::ics("UID:organizer\nSUMMARY:Organized by actor\nDTSTART:20260311T140000Z\nDTEND:20260311T150000Z\nORGANIZER:mailto:alice@example.invalid\nATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:alice@example.invalid"));
        $this->store->addObject(3, 'shared-public.ics', self::ics("UID:shared-public\nSUMMARY:Shared copy\nCLASS:PUBLIC\nDTSTART:20260311T150000Z\nDTEND:20260311T160000Z\nATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:alice@example.invalid"));
        $this->store->addObject(3, 'hidden.ics', self::ics("UID:hidden\nSUMMARY:Private invitation\nCLASS:PRIVATE\nDTSTART:20260311T170000Z\nDTEND:20260311T180000Z\nATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:alice@example.invalid"));
        $time = $this->createMock(ITimeFactory::class);
        $time->method('now')->willReturn(new \DateTimeImmutable('2026-03-10T12:00:00Z'));
        $users = $this->createMock(IUserManager::class);
        $actor = $this->createMock(IUser::class);
        $actor->method('isEnabled')->willReturn(true);
        $actor->method('getEMailAddress')->willReturn('alice@example.invalid');
        $users->method('get')->with('alice')->willReturn($actor);
        $reader = new EventSearch(
            \OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store, $this->scope), $this->store, $this->store,
            new EventRepository($this->store, new Classification()), new Classification(), new EventExpander(), new EventMapper(), new EventDates(), $time, new NullLogger(), $users,
        );

        $page = $this->decode($reader->execute([
            'from' => '2026-03-11T00:00:00Z', 'to' => '2026-03-12T00:00:00Z', 'participation_status' => 'needs-action',
        ], 'alice'));

        self::assertSame(['own-pending'], array_column($page['items'], 'uid'));
    }


    public function testSearchReturnsOccurrenceReferenceForPendingRecurringInvitation(): void
    {
        $this->store->addObject(1, 'series.ics', self::ics("UID:series\nSUMMARY:Invitation\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z\nRRULE:FREQ=DAILY;COUNT=2\nATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:alice@example.invalid"));
        $time = $this->createMock(ITimeFactory::class);
        $time->method('now')->willReturn(new \DateTimeImmutable('2026-03-10T12:00:00Z'));
        $users = $this->createMock(IUserManager::class);
        $actor = $this->createMock(IUser::class);
        $actor->method('isEnabled')->willReturn(true);
        $actor->method('getEMailAddress')->willReturn('alice@example.invalid');
        $users->method('get')->with('alice')->willReturn($actor);
        $reader = new EventSearch(
            \OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store, $this->scope), $this->store, $this->store,
            new EventRepository($this->store, new Classification()), new Classification(), new EventExpander(), new EventMapper(), new EventDates(), $time, new NullLogger(), $users,
        );

        $page = $this->decode($reader->execute([
            'from'=>'2026-03-11T00:00:00Z','to'=>'2026-03-13T00:00:00Z','participation_status'=>'needs-action',
        ],'alice'));

        self::assertCount(2,$page['items']);
        self::assertSame('2026-03-11T09:00:00.000Z',$page['items'][0]['recurrence_id']);
        self::assertSame('2026-03-12T09:00:00.000Z',$page['items'][1]['recurrence_id']);
    }

    private function decode(array $result): array
    {
        return json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
    }
}
