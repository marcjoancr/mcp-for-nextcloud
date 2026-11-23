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
use Psr\Log\NullLogger;

final class BrowseEventsTest extends CalendarTestCase
{
    public function testSearchDoesNotLeakPrivateSharedEventByMatchingItsSummary(): void
    {
        $this->store->addObject(3, 'hidden.ics', self::ics("UID:hidden\nSUMMARY:Secret planning needle\nCLASS:PRIVATE\nDTSTART:20260311T150000Z\nDTEND:20260311T160000Z"));
        $this->store->addObject(3, 'public.ics', self::ics("UID:public\nSUMMARY:Public planning\nDTSTART:20260311T170000Z\nDTEND:20260311T180000Z"));
        $time = $this->createMock(ITimeFactory::class);
        $time->method('now')->willReturn(new \DateTimeImmutable('2026-03-10T12:00:00Z'));
        $reader = new EventSearch(
            \OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store, $this->scope),
            $this->store,
            $this->store,
            new EventRepository($this->store, new Classification()),
            new Classification(), new EventExpander(), new EventMapper(), new EventDates(), $time, new NullLogger(),
        );
        $result = $reader->execute(['from' => '2026-03-11T00:00:00Z', 'to' => '2026-03-12T00:00:00Z', 'query' => 'needle'], 'alice');
        self::assertSame([], $this->decode($result)['items']);
        self::assertStringNotContainsString('Secret planning needle', json_encode($result));
    }

    public function testSearchFindsVisibleOccurrenceAndPaginates(): void
    {
        $this->store->addObject(1, 'one.ics', self::ics("UID:one\nSUMMARY:Planning\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z"));
        $this->store->addObject(1, 'two.ics', self::ics("UID:two\nSUMMARY:Planning review\nDTSTART:20260311T110000Z\nDTEND:20260311T120000Z"));
        $time = $this->createMock(ITimeFactory::class);
        $time->method('now')->willReturn(new \DateTimeImmutable('2026-03-10T12:00:00Z'));
        $reader = new EventSearch(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store, $this->scope), $this->store, $this->store,
            new EventRepository($this->store, new Classification()), new Classification(), new EventExpander(), new EventMapper(), new EventDates(), $time, new NullLogger());
        $result = $reader->execute(['from' => '2026-03-11T00:00:00Z', 'to' => '2026-03-12T00:00:00Z', 'query' => 'planning', 'limit' => 1], 'alice');
        $page = $this->decode($result);
        self::assertCount(1, $page['items']);
        self::assertSame('one', $page['items'][0]['uid']);
        self::assertSame(1, $page['nextOffset']);
        self::assertTrue($page['hasMore']);
    }

    public function testDetailReturnsVisibleDescriptionAndAttendeeResponse(): void
    {
        $this->store->addObject(1, 'detail.ics', self::ics("UID:detail\nSUMMARY:Planning\nDESCRIPTION:Agenda details\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z\nORGANIZER:mailto:alice@example.invalid\nATTENDEE;CN=Roberto;PARTSTAT=ACCEPTED:mailto:bob@example.invalid"));
        $refs = $this->createMock(\OCA\Mcp\Modules\Calendar\Contracts\EventReferenceReader::class);
        $refs->method('read')->willReturn(['title' => 'Planning', 'url' => 'https://cloud.example.com/event']);
        $reader = new \OCA\Mcp\Modules\Calendar\Reading\EventDetailReader(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store, $this->scope), $this->store,
            new EventRepository($this->store, new Classification()), new Classification(), new EventExpander(), new EventDates(), $refs);
        $result = $this->decode($reader->execute(['calendar' => self::PERSONAL, 'uid' => 'detail'], 'alice'));
        self::assertSame('Agenda details', $result['description']);
        self::assertSame('ACCEPTED', $result['attendees'][0]['partstat']);
        self::assertSame('https://cloud.example.com/event', $result['url']);
    }

    public function testDetailUsesTheExclusiveEndForAnAllDayEventWithoutDtend(): void
    {
        $this->store->addObject(1, 'holiday.ics', self::ics("UID:holiday\nSUMMARY:Holiday\nDTSTART;VALUE=DATE:20260311"));
        $result = $this->decode($this->registry->call('calendar_read_event', ['calendar' => self::PERSONAL, 'uid' => 'holiday'], 'alice'));
        self::assertTrue($result['allDay']);
        self::assertSame('2026-03-11', $result['start']);
        self::assertSame('2026-03-12', $result['end']);
    }

    public function testDetailHidesPrivateSharedEventAsMissing(): void
    {
        $this->store->addObject(3, 'secret.ics', self::ics("UID:secret\nSUMMARY:Secret\nDESCRIPTION:Hidden details\nCLASS:PRIVATE\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z"));
        $refs = $this->createMock(\OCA\Mcp\Modules\Calendar\Contracts\EventReferenceReader::class);
        $reader = new \OCA\Mcp\Modules\Calendar\Reading\EventDetailReader(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store, $this->scope), $this->store,
            new EventRepository($this->store, new Classification()), new Classification(), new EventExpander(), new EventDates(), $refs);
        try { $reader->execute(['calendar' => self::TEAM, 'uid' => 'secret'], 'alice'); self::fail('hidden event should not be readable'); }
        catch (\OCA\Mcp\Modules\Calendar\Execution\CalendarException $e) { self::assertStringNotContainsString('Hidden details', $e->getMessage()); }
    }

    private function decode(array $result): array
    {
        return json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
    }
}
