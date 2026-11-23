<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Calendar;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventDates;
use OCA\Mcp\Modules\Calendar\Events\EventExpander;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Reading\EventDetailReader;
use OCA\Mcp\Tests\Support\Calendar\CalendarTestCase;
use OCA\Mcp\Tests\Support\Dav\ScopeStub;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\NullLogger;

final class RecurrenceReadContractTest extends CalendarTestCase
{
    public function testListReturnsStableAllDayRecurrenceIdForEachOccurrence(): void
    {
        $this->store->addObject(1,'all-day.ics',self::ics("UID:all-day\nSUMMARY:Daily\nDTSTART;VALUE=DATE:20260310\nDTEND;VALUE=DATE:20260311\nRRULE:FREQ=DAILY;COUNT=2"));
        $items = self::json($this->call('calendar_list_events',['calendar'=>self::PERSONAL,'from'=>'2026-03-10','to'=>'2026-03-12']));
        self::assertSame(['2026-03-10','2026-03-11'],array_column($items,'recurrence_id'));
    }

    public function testAllDayOccurrenceDetailReturnsItsCivilRecurrenceId(): void
    {
        $this->store->addObject(1,'all-day.ics',self::ics("UID:all-day\nSUMMARY:Daily\nDTSTART;VALUE=DATE:20260310\nDTEND;VALUE=DATE:20260311\nRRULE:FREQ=DAILY;COUNT=2"));
        $reader=$this->detailReader();
        $item=self::json($reader->execute(['calendar'=>self::PERSONAL,'uid'=>'all-day','recurrence_id'=>'2026-03-11'],'alice'));
        self::assertSame('2026-03-11',$item['recurrence_id']);
    }

    public function testMovedOverrideDetailKeepsOriginalRecurrenceIdentity(): void
    {
        $extra="BEGIN:VEVENT\nUID:moved\nRECURRENCE-ID:20260311T090000Z\nSUMMARY:Moved\nDTSTART:20260312T150000Z\nDTEND:20260312T160000Z\nEND:VEVENT";
        $this->store->addObject(1,'moved.ics',self::ics("UID:moved\nSUMMARY:Weekly\nDTSTART:20260310T090000Z\nDTEND:20260310T100000Z\nRRULE:FREQ=DAILY;COUNT=3",$extra));
        $item=json_decode($this->detailReader()->execute(['calendar'=>self::PERSONAL,'uid'=>'moved','recurrence_id'=>'2026-03-11T09:00:00Z'],'alice')['content'][0]['text'],true,flags:JSON_THROW_ON_ERROR);
        self::assertSame('2026-03-12T15:00:00Z',$item['start']);
        self::assertSame('2026-03-11T09:00:00Z',$item['recurrence_id']);
    }

    public function testBusySharedDetailDoesNotReturnRealEventStatusOrParticipants(): void
    {
        $this->store->addObject(3,'busy.ics',self::ics("UID:busy\nSUMMARY:Confidential\nCLASS:CONFIDENTIAL\nSTATUS:CANCELLED\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z\nORGANIZER:mailto:bob@example.invalid\nATTENDEE:mailto:alice@example.invalid"));
        $item=self::json($this->detailReader()->execute(['calendar'=>self::TEAM,'uid'=>'busy'],'alice'));
        self::assertSame('Ocupado',$item['summary']);
        self::assertArrayNotHasKey('status',$item);
        self::assertArrayNotHasKey('organizer',$item);
        self::assertArrayNotHasKey('attendees',$item);
    }

    public function testMonthlyEndOfMonthSeriesSkipsMonthsWithoutTheStartDay(): void
    {
        $this->store->addObject(1,'month-end.ics',self::ics("UID:month-end\nSUMMARY:Month end\nDTSTART;VALUE=DATE:20260131\nDTEND;VALUE=DATE:20260201\nRRULE:FREQ=MONTHLY;BYMONTHDAY=31;UNTIL=20260531"));
        $classification=new Classification(); $access=ScopeStub::access($this->store,$this->scope);
        $clock=$this->createMock(ITimeFactory::class); $clock->method('now')->willReturn(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $reader=new \OCA\Mcp\Modules\Calendar\Reading\EventReader($access,$this->store,$this->store,new EventRepository($this->store,$classification),$classification,new EventExpander(),new \OCA\Mcp\Modules\Calendar\Events\EventMapper(),new EventDates(),$clock,new NullLogger());
        $items=json_decode($reader->execute(['calendar'=>self::PERSONAL,'from'=>'2026-01-31','to'=>'2026-04-01'],'alice')['content'][0]['text'],true,flags:JSON_THROW_ON_ERROR);
        self::assertSame(['2026-01-31','2026-03-31'],array_column($items,'recurrence_id'));
    }

    private function detailReader(): EventDetailReader
    {
        $refs=$this->createMock(\OCA\Mcp\Modules\Calendar\Contracts\EventReferenceReader::class);
        $refs->method('read')->willReturn(['title'=>'x','url'=>'https://example.invalid/event']);
        $classification=new Classification();
        return new EventDetailReader(\OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store,$this->scope),$this->store,
            new EventRepository($this->store,$classification),$classification,new EventExpander(),new EventDates(),$refs);
    }
}
