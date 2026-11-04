<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventDates;
use OCA\Mcp\Modules\Calendar\Events\EventExpander;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityReader;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Tests\Support\Calendar\CalendarTestCase;

final class AvailabilityTest extends CalendarTestCase {
	private AvailabilityReader $availability;

	protected function setUp(): void {
		parent::setUp();
		$access = \OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store, $this->scope);
		$this->availability = new AvailabilityReader(
			$access,
			$this->store,
			$this->store,
			new EventRepository($this->store, new Classification()),
			new Classification(),
			new EventExpander(),
			new EventDates(),
		);
	}

	public function testBusyAndFreeIntervalsUseHalfOpenUtcWindowsAndMinimumDuration(): void {
		$this->store->addObject(1, 'one.ics', self::ics("UID:one\nDTSTART:20261104T100000Z\nDTEND:20261104T110000Z"));
		$this->store->addObject(1, 'touching.ics', self::ics("UID:touching\nDTSTART:20261104T110000Z\nDTEND:20261104T120000Z"));

		$result = $this->availability->execute([
			'calendars' => [self::PERSONAL],
			'from' => '2026-11-04T09:00:00Z',
			'to' => '2026-11-04T13:00:00Z',
			'min_duration_minutes' => 60,
		], 'alice');

		self::assertSame([
			'from' => '2026-11-04T09:00:00Z',
			'to' => '2026-11-04T13:00:00Z',
			'calendars' => [self::PERSONAL],
			'busy' => [
				['calendar' => self::PERSONAL, 'start' => '2026-11-04T10:00:00Z', 'end' => '2026-11-04T12:00:00Z'],
			],
			'conflicts' => [],
			'free' => [
				['start' => '2026-11-04T09:00:00Z', 'end' => '2026-11-04T10:00:00Z'],
				['start' => '2026-11-04T12:00:00Z', 'end' => '2026-11-04T13:00:00Z'],
			],
		], json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR));
	}

	public function testOverlappingEventsReturnConflictIntervalsWithoutEventDetails(): void {
		$this->store->addObject(1, 'one.ics', self::ics("UID:one\nSUMMARY:First secret\nDTSTART:20261104T100000Z\nDTEND:20261104T120000Z"));
		$this->store->addObject(1, 'two.ics', self::ics("UID:two\nSUMMARY:Second secret\nDTSTART:20261104T110000Z\nDTEND:20261104T130000Z"));

		$result = $this->availability->execute(['calendars' => [self::PERSONAL], 'from' => '2026-11-04T09:00:00Z', 'to' => '2026-11-04T14:00:00Z'], 'alice');
		$data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

		self::assertSame([[
			'calendars' => [self::PERSONAL],
			'start' => '2026-11-04T11:00:00Z',
			'end' => '2026-11-04T12:00:00Z',
			'overlappingEvents' => 2,
		]], $data['conflicts']);
		self::assertStringNotContainsString('secret', $result['content'][0]['text']);
	}

	public function testRecurringExclusionsAndOverridesProduceAccurateBusyTime(): void {
		$this->store->addObject(1, 'series.ics', self::ics(
			"UID:series\nDTSTART;TZID=America/New_York:20261031T100000\nDTEND;TZID=America/New_York:20261031T110000\nRRULE:FREQ=DAILY;COUNT=4\nEXDATE;TZID=America/New_York:20261102T100000",
			"BEGIN:VEVENT\nUID:series\nRECURRENCE-ID;TZID=America/New_York:20261103T100000\nDTSTART;TZID=America/New_York:20261103T113000\nDTEND;TZID=America/New_York:20261103T123000\nEND:VEVENT"
		));
		$this->store->addObject(1, 'all-day.ics', self::ics("UID:all-day\nDTSTART;VALUE=DATE:20261102\nDTEND;VALUE=DATE:20261103"));

		$result = $this->availability->execute([
			'calendars' => [self::PERSONAL],
			'from' => '2026-10-31T00:00:00Z',
			'to' => '2026-11-04T00:00:00Z',
		], 'alice');
		$data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

		self::assertSame([
			['calendar' => self::PERSONAL, 'start' => '2026-10-31T14:00:00Z', 'end' => '2026-10-31T15:00:00Z'],
			['calendar' => self::PERSONAL, 'start' => '2026-11-01T15:00:00Z', 'end' => '2026-11-01T16:00:00Z'],
			['calendar' => self::PERSONAL, 'start' => '2026-11-02T00:00:00Z', 'end' => '2026-11-03T00:00:00Z'],
			['calendar' => self::PERSONAL, 'start' => '2026-11-03T16:30:00Z', 'end' => '2026-11-03T17:30:00Z'],
		], $data['busy']);
	}

	public function testTransparentAndCancelledEventsDoNotBlockTime(): void {
		$this->store->addObject(1, 'ignored.ics', self::ics("UID:transparent\nDTSTART:20261104T100000Z\nDTEND:20261104T110000Z\nTRANSP:TRANSPARENT"));
		$this->store->addObject(1, 'cancelled.ics', self::ics("UID:cancelled\nDTSTART:20261104T110000Z\nDTEND:20261104T120000Z\nSTATUS:CANCELLED"));

		$result = $this->availability->execute(['calendars' => [self::PERSONAL], 'from' => '2026-11-04T09:00:00Z', 'to' => '2026-11-04T13:00:00Z'], 'alice');
		$data = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

		self::assertSame([], $data['busy']);
		self::assertSame([], $data['conflicts']);
		self::assertSame([['start' => '2026-11-04T09:00:00Z', 'end' => '2026-11-04T13:00:00Z']], $data['free']);
	}

	public function testPrivateSharedEventFailsClosedWithoutRevealingTimingOrContent(): void {
		$this->store->addObject(3, 'private.ics', self::ics('UID:private\nSUMMARY:Secret appointment\nCLASS:PRIVATE\nDTSTART:20261104T100000Z\nDTEND:20261104T110000Z'));

		$result = $this->availability->execute(['calendars' => [self::TEAM], 'from' => '2026-11-04T09:00:00Z', 'to' => '2026-11-04T13:00:00Z'], 'alice');

		self::assertTrue($result['isError'] ?? false);
		self::assertSame('Não foi possível verificar a disponibilidade dos calendários.', $result['content'][0]['text']);
		self::assertStringNotContainsString('Secret appointment', $result['content'][0]['text']);
		self::assertStringNotContainsString('10:00', $result['content'][0]['text']);
	}

	public function testMalformedObjectOrIncompleteDavScanCannotReturnFreeTime(): void {
		$this->store->addObject(1, 'bad.ics', 'not an icalendar object');
		$result = $this->availability->execute(['calendars' => [self::PERSONAL], 'from' => '2026-11-04T09:00:00Z', 'to' => '2026-11-04T13:00:00Z'], 'alice');
		self::assertTrue($result['isError'] ?? false);
		self::assertSame('Não foi possível verificar a disponibilidade dos calendários.', $result['content'][0]['text']);

		$this->store->addObject(1, 'one.ics', self::ics("UID:one\nDTSTART:20261104T100000Z\nDTEND:20261104T110000Z"));
		$this->store->rangeFailure = new \RuntimeException('database details');
		$result = $this->availability->execute(['calendars' => [self::PERSONAL], 'from' => '2026-11-04T09:00:00Z', 'to' => '2026-11-04T13:00:00Z'], 'alice');
		self::assertTrue($result['isError'] ?? false);
		self::assertSame('Não foi possível verificar a disponibilidade dos calendários.', $result['content'][0]['text']);
	}

	public function testIncompleteEventAndGlobalOccurrenceLimitFailClosed(): void {
		$this->store->addObject(1, 'missing-start.ics', self::ics("UID:missing-start\nSUMMARY:No start"));
		$result = $this->availability->execute(['calendars' => [self::PERSONAL], 'from' => '2026-11-04T09:00:00Z', 'to' => '2026-11-04T13:00:00Z'], 'alice');
		self::assertTrue($result['isError'] ?? false);

		for ($i = 0; $i < 323; $i++) {
			$this->store->addObject(1, 'series-' . $i . '.ics', self::ics("UID:series-$i\nDTSTART:20261101T100000Z\nDTEND:20261101T101000Z\nRRULE:FREQ=DAILY;COUNT=40"));
		}
		$result = $this->availability->execute(['calendars' => [self::PERSONAL], 'from' => '2026-11-01T00:00:00Z', 'to' => '2026-12-02T00:00:00Z'], 'alice');
		self::assertTrue($result['isError'] ?? false, 'the scan must not return free ranges after exceeding its occurrence bound');
		self::assertSame('Não foi possível verificar a disponibilidade dos calendários.', $result['content'][0]['text']);
	}

	public function testWindowAndMinimumDurationBoundsAreEnforced(): void {
		try {
			$this->availability->execute(['from' => '2026-11-01', 'to' => '2026-12-03'], 'alice');
			self::fail('the range exceeds 31 days');
		} catch (CalendarException $e) {
			self::assertStringContainsString('31 dias', $e->getMessage());
		}

		try {
			$this->availability->execute(['calendars' => [self::PERSONAL], 'from' => '2026-11-01', 'to' => '2026-11-02', 'min_duration_minutes' => 1441], 'alice');
			self::fail('the minimum duration exceeds its bound');
		} catch (CalendarArgumentException $e) {
			self::assertSame('min_duration_minutes', $e->details()['field']);
		}
	}
}
