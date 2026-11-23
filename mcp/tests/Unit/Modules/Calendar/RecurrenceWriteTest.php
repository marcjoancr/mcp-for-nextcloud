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
use Sabre\VObject\ITip\Broker;

final class RecurrenceWriteTest extends CalendarTestCase
{
    public function testCreatesBoundedWeeklyRuleAndPreservesNativePayload(): void
    {
        $item=self::json($this->call('calendar_create_event',['calendar'=>self::PERSONAL,'summary'=>'Weekly','start'=>'2026-03-02T09:00:00Z','end'=>'2026-03-02T10:00:00Z','recurrence'=>['frequency'=>'WEEKLY','interval'=>1,'byDay'=>['MO','WE'],'until'=>'2026-03-31']]));
        $event=Reader::read($this->store->objects[1][$item['uid'].'.ics']['data'])->VEVENT;
        self::assertSame('FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20260331T235959Z',(string)$event->RRULE);
        self::assertSame('put',$this->dav->calls[0][0], 'CalDAV payload was dispatched through the native writer');
    }

    public function testMonthlyRuleUsesStartDayAndKeepsAllDayUntilCivil(): void
    {
        $item=self::json($this->call('calendar_create_event',['calendar'=>self::PERSONAL,'summary'=>'Month end','start'=>'2026-01-31','end'=>'2026-02-01','allDay'=>true,'recurrence'=>['frequency'=>'MONTHLY','until'=>'2026-05-31']]));
        $event=Reader::read($this->store->objects[1][$item['uid'].'.ics']['data'])->VEVENT;
        self::assertSame('FREQ=MONTHLY;BYMONTHDAY=31;UNTIL=20260531',(string)$event->RRULE);
    }

    public function testRejectsUnboundedOrInvalidRecurrenceBeforeWriting(): void
    {
        try {
            $this->call('calendar_create_event',['calendar'=>self::PERSONAL,'summary'=>'No end','start'=>'2026-03-02T09:00:00Z','end'=>'2026-03-02T10:00:00Z','recurrence'=>['frequency'=>'WEEKLY']]);
            self::fail('recurrence requires a finite until date');
        } catch (CalendarArgumentException) {}
        $this->assertNoWrites();
    }

    public function testEditsOneOccurrenceWithoutChangingMasterOrNeighbours(): void
    {
        $this->store->addObject(1,'series.ics',self::ics("UID:series\nSUMMARY:Weekly\nDTSTART;TZID=Europe/Madrid:20260302T090000\nDTEND;TZID=Europe/Madrid:20260302T100000\nRRULE:FREQ=WEEKLY;COUNT=4"));
        $etag=$this->store->objects[1]['series.ics']['etag'];
        $item=self::json($this->call('calendar_update_event',['calendar'=>self::PERSONAL,'uid'=>'series','recurrence_id'=>'2026-03-09T08:00:00Z','summary'=>'Moved occurrence','start'=>'2026-03-09T11:00:00Z','end'=>'2026-03-09T12:00:00Z']));
        $written=Reader::read($this->store->objects[1]['series.ics']['data']);
        self::assertSame('Weekly',(string)$written->VEVENT->SUMMARY);
        $override=$written->select('VEVENT')[1];
        self::assertSame('20260309T090000',$override->{'RECURRENCE-ID'}->getValue());
        self::assertSame('Moved occurrence',(string)$override->SUMMARY);
        self::assertSame('2026-03-09T11:00:00.000Z',$item['start']);
        self::assertSame('update',$this->dav->calls[0][0]);
        self::assertSame($etag,$this->dav->calls[0][1][2]);
        self::assertSame($this->store->objects[1]['series.ics']['etag'],$item['etag']);
    }

    public function testPreviewShowsTheSelectedOccurrenceAndDoesNotWrite(): void
    {
        $this->store->addObject(1,'series.ics',self::ics("UID:series\nSUMMARY:Weekly\nDTSTART:20260302T090000Z\nDTEND:20260302T100000Z\nRRULE:FREQ=WEEKLY;COUNT=3"));
        $plan=self::json($this->registry->call('calendar_update_event',['calendar'=>self::PERSONAL,'uid'=>'series','recurrence_id'=>'2026-03-09T09:00:00Z','start'=>'2026-03-09T11:00:00Z','end'=>'2026-03-09T12:00:00Z'],'alice'));
        self::assertSame('2026-03-09T09:00:00+00:00',$plan['before']['start']);
        self::assertSame('2026-03-09T11:00:00+00:00',$plan['after']['start']);
        self::assertSame('2026-03-09T09:00:00Z',$plan['recurrence_id']);
        $this->assertNoWrites();
    }

    public function testPreviewShowsTheFiniteRuleBeforeCreateWrites(): void
    {
        $plan=self::json($this->registry->call('calendar_create_event',['calendar'=>self::PERSONAL,'summary'=>'Weekly','start'=>'2026-03-02T09:00:00Z','end'=>'2026-03-02T10:00:00Z','recurrence'=>['frequency'=>'WEEKLY','until'=>'2026-03-31']],'alice'));
        self::assertSame('FREQ=WEEKLY;BYDAY=MO;UNTIL=20260331T235959Z',$plan['after']['recurrenceRule']);
        $this->assertNoWrites();
    }

    public function testEditingAnAlreadyMovedOverrideUsesItsOriginalRecurrenceId(): void
    {
        $extra="BEGIN:VEVENT\nUID:series\nRECURRENCE-ID;TZID=Europe/Madrid:20260309T090000\nSUMMARY:Moved\nDTSTART;TZID=Europe/Madrid:20260310T150000\nDTEND;TZID=Europe/Madrid:20260310T160000\nEND:VEVENT";
        $this->store->addObject(1,'series.ics',self::ics("UID:series\nSUMMARY:Weekly\nDTSTART;TZID=Europe/Madrid:20260302T090000\nDTEND;TZID=Europe/Madrid:20260302T100000\nRRULE:FREQ=WEEKLY;COUNT=3",$extra));
        $item=self::json($this->call('calendar_update_event',['calendar'=>self::PERSONAL,'uid'=>'series','recurrence_id'=>'2026-03-09T08:00:00Z','summary'=>'Moved again']));
        $written=Reader::read($this->store->objects[1]['series.ics']['data']);
        $override=$written->select('VEVENT')[1];
        self::assertSame('20260309T090000',$override->{'RECURRENCE-ID'}->getValue());
        self::assertSame('20260310T150000',$override->DTSTART->getValue());
        self::assertSame('Moved again',(string)$override->SUMMARY);
        self::assertSame('2026-03-09T08:00:00.000Z',$item['recurrence_id']);
    }

    public function testCancelPreviewNamesTheOccurrenceAndDoesNotClaimDelivery(): void
    {
        $this->store->addObject(1,'series.ics',self::ics("UID:series\nSUMMARY:Weekly\nDTSTART:20260302T090000Z\nDTEND:20260302T100000Z\nRRULE:FREQ=WEEKLY;COUNT=3\nATTENDEE:mailto:bob@example.invalid"));
        $plan=self::json($this->registry->call('calendar_delete_event',['calendar'=>self::PERSONAL,'uid'=>'series','recurrence_id'=>'2026-03-09T09:00:00Z','send_invitations'=>true],'alice'));
        self::assertFalse($plan['recoverable']);
        self::assertStringContainsString('excluída da série',$plan['consequence']);
        self::assertStringContainsString('atualização REQUEST',$plan['scheduling']['message']);
        self::assertFalse($plan['scheduling']['participantsNotified']);
        $this->assertNoWrites();
    }

    public function testCancelsOneOccurrenceWithNativeScheduledOverrideNotTrashDelete(): void
    {
        $extra="BEGIN:VEVENT\nUID:series\nRECURRENCE-ID:20260309T090000Z\nSUMMARY:Moved\nDTSTART:20260309T140000Z\nDTEND:20260309T150000Z\nORGANIZER:mailto:alice@example.invalid\nATTENDEE;PARTSTAT=ACCEPTED:mailto:bob@example.invalid\nEND:VEVENT";
        $before=self::ics("UID:series\nSUMMARY:Weekly\nDTSTART:20260302T090000Z\nDTEND:20260302T100000Z\nRRULE:FREQ=WEEKLY;COUNT=4\nORGANIZER:mailto:alice@example.invalid\nATTENDEE;PARTSTAT=ACCEPTED:mailto:bob@example.invalid\nATTENDEE;PARTSTAT=TENTATIVE:mailto:carla@example.invalid",$extra);
        $this->store->addObject(1,'series.ics',$before);
        $result=self::json($this->call('calendar_delete_event',['calendar'=>self::PERSONAL,'uid'=>'series','recurrence_id'=>'2026-03-09T09:00:00Z','send_invitations'=>true]));
        $written=Reader::read($this->store->objects[1]['series.ics']['data']);
        self::assertCount(1,$written->select('VEVENT'),'only the master remains after excluding the moved occurrence');
        self::assertSame('20260309T090000Z',$written->VEVENT->EXDATE->getValue());
        self::assertSame('ACCEPTED',(string)$written->VEVENT->select('ATTENDEE')[0]['PARTSTAT']);
        self::assertSame('TENTATIVE',(string)$written->VEVENT->select('ATTENDEE')[1]['PARTSTAT']);
        self::assertSame('update',$this->dav->calls[0][0]);
        self::assertTrue($this->dav->calls[0][1][4]);
        self::assertFalse(isset($result['recoverable']));
        self::assertSame('EXDATE',$result['cancellation']);
        self::assertTrue($result['scheduling']['requested']);
        $messages=(new Broker())->parseEvent($written,['mailto:alice@example.invalid'],Reader::read($before));
        self::assertCount(2,$messages);
        self::assertEqualsCanonicalizing(['mailto:bob@example.invalid','mailto:carla@example.invalid'],array_map(static fn($m)=>$m->recipient,$messages));
        foreach ($messages as $message) {
            self::assertSame('REQUEST',$message->method);
            self::assertCount(1,$message->message->VEVENT->select('EXDATE'));
            self::assertSame('FREQ=WEEKLY;COUNT=4',(string)$message->message->VEVENT->RRULE);
            self::assertCount(2,$message->message->VEVENT->select('ATTENDEE'));
        }
    }
}
