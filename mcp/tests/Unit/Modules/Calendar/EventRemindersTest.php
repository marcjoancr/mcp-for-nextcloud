<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice;
use OCA\Mcp\Modules\Calendar\Editing\EventReminders;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Tests\Support\Calendar\CalendarTestCase;
use OCA\Mcp\Tests\Support\Dav\ScopeStub;
use OCP\IConfig;
use OCP\IUserManager;
use Sabre\VObject\Reader;

final class EventRemindersTest extends CalendarTestCase {
    public function testPreviewAndExecutionPreserveExistingEmailAlarmAndDisableScheduling(): void {
        $this->seed();
        $tool = $this->reminders();
        $args = ['calendar' => self::PERSONAL, 'uid' => 'event', 'minutes_before' => [60, 10]];
        $plan = $tool->preview($args, 'alice');
        self::assertSame([20], $plan['before']);
        self::assertSame([10, 60], $plan['after']);
        self::assertSame(2, $plan['preserved_alarms']);
        $this->assertNoWrites();

        $result = self::json($tool->execute($args + ['plan_state' => $plan['plan_state']], 'alice'));
        self::assertSame([10, 60], $result['minutes_before']);
        self::assertFalse($this->dav->calls[0][1][4]);
        $event = Reader::read($this->store->objects[1]['event.ics']['data'])->VEVENT;
        $alarms = array_values($event->select('VALARM'));
        self::assertCount(4, $alarms);
        self::assertSame('EMAIL', (string)$alarms[0]->ACTION);
        self::assertSame('mailto:external@example.invalid', (string)$alarms[0]->ATTENDEE);
        self::assertSame('Leave for the station', (string)$alarms[1]->DESCRIPTION);
        self::assertSame('local', (string)$alarms[1]->{'X-CUSTOM-ALARM'});
        self::assertSame('Meeting', (string)$event->SUMMARY);
    }

    public function testAChangedEventCannotBeOverwrittenByTheOldPlan(): void {
        $this->seed();
        $tool = $this->reminders();
        $args = ['calendar' => self::PERSONAL, 'uid' => 'event', 'minutes_before' => []];
        $plan = $tool->preview($args, 'alice');
        $this->store->objects[1]['event.ics']['etag'] = '"changed"';
        $this->expectException(PlanChanged::class);
        try {
            $tool->execute($args + ['plan_state' => $plan['plan_state']], 'alice');
        } finally {
            $this->assertNoWrites();
        }
    }

    private function seed(): void {
        $this->store->addObject(1, 'event.ics', self::ics(
            "UID:event\nSUMMARY:Meeting\nDTSTART:20261201T100000Z\nDTEND:20261201T110000Z\n"
            . "BEGIN:VALARM\nACTION:DISPLAY\nTRIGGER:-PT20M\nDESCRIPTION:Reminder\nEND:VALARM\n"
            . "BEGIN:VALARM\nACTION:EMAIL\nTRIGGER:-PT30M\nATTENDEE:mailto:external@example.invalid\nDESCRIPTION:Existing\nEND:VALARM\n"
            . "BEGIN:VALARM\nACTION:DISPLAY\nTRIGGER:-PT45M\nDESCRIPTION:Leave for the station\nX-CUSTOM-ALARM:local\nEND:VALARM",
        ));
    }

    private function reminders(): EventReminders {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->willReturn('test-secret');
        return new EventReminders(
            ScopeStub::access($this->store, $this->scope),
            new EventRepository($this->store, new Classification()),
            $this->dav,
            new SharedCollectionNotice($this->createMock(IUserManager::class)),
            new PlanState($config),
        );
    }
}
