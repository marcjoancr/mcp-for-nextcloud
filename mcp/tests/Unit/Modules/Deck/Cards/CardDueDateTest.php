<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Cards;

use DateTimeZone;
use InvalidArgumentException;
use OCA\Mcp\Modules\Deck\Cards\CardDueDate;
use OCA\Mcp\Modules\Deck\Cards\CardInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * UTC wire values preserve calendar-day intent across real timezone offsets and seasonal changes.
 */
final class CardDueDateTest extends TestCase {
	/**
	 * Independent UTC expectations west/east of Greenwich, including summer and winter offsets.
	 *
	 * @return iterable<string, array{string, string, string}> Day, account timezone and UTC wire value.
	 */
	public static function days(): iterable {
		yield 'UTC' => ['2026-10-01', 'UTC', '2026-10-01T00:00:00Z'];
		yield 'Brazil' => ['2026-10-01', 'America/Sao_Paulo', '2026-10-01T03:00:00Z'];
		yield 'Japan previous UTC day' => ['2026-10-01', 'Asia/Tokyo', '2026-09-30T15:00:00Z'];
		yield 'New York winter' => ['2026-01-15', 'America/New_York', '2026-01-15T05:00:00Z'];
		yield 'New York summer' => ['2026-07-15', 'America/New_York', '2026-07-15T04:00:00Z'];
		yield 'Berlin winter' => ['2026-01-15', 'Europe/Berlin', '2026-01-14T23:00:00Z'];
		yield 'Berlin summer' => ['2026-07-15', 'Europe/Berlin', '2026-07-14T22:00:00Z'];
		yield 'Santiago missing midnight' => ['2026-09-06', 'America/Santiago', '2026-09-06T04:00:00Z'];
		yield 'Havana repeated midnight' => ['2026-11-01', 'America/Havana', '2026-11-01T04:00:00Z'];
		yield 'Far east' => ['2026-10-01', 'Pacific/Kiritimati', '2026-09-30T10:00:00Z'];
	}

	/**
	 * Match the exact UTC storage spelling, then simulate timezone-less hydration and local rendering.
	 *
	 * @param string $day Validated input day.
	 * @param string $zone Account timezone.
	 * @param string $utc Independent expected UTC wire value.
	 * @return void
	 */
	#[DataProvider('days')]
	public function testCalendarDayIsSentInUtc(string $day, string $zone, string $utc): void {
		$stored = CardDueDate::forDeck($day, new DateTimeZone($zone));
		self::assertSame($utc, $stored);
		$hydrated = new \DateTimeImmutable(substr($stored, 0, 19), new DateTimeZone('UTC'));
		self::assertSame($day, $hydrated->setTimezone(new DateTimeZone($zone))->format('Y-m-d'));
	}

	/**
	 * Confirm the container tzdata gap and overlap, including the native occurrence selected at midnight.
	 *
	 * @return void
	 */
	public function testNativeMidnightTransitionPolicy(): void {
		$santiago = CardDueDate::midnight('2026-09-06', new DateTimeZone('America/Santiago'));
		$havana = CardDueDate::midnight('2026-11-01', new DateTimeZone('America/Havana'));
		self::assertSame('2026-09-06T01:00:00-03:00', $santiago->format(\DateTimeInterface::ATOM));
		self::assertSame('2026-11-01T00:00:00-04:00', $havana->format(\DateTimeInterface::ATOM));
		$later = new \DateTimeImmutable('2026-11-01T05:00:00Z');
		self::assertSame('2026-11-01T00:00:00-05:00', $later->setTimezone(new DateTimeZone('America/Havana'))->format(\DateTimeInterface::ATOM));
		fwrite(STDERR, sprintf("Midnight tzdata=%s Santiago=%s Havana=%s later=%s\n", timezone_version_get(), $santiago->format(\DateTimeInterface::ATOM), $havana->format(\DateTimeInterface::ATOM), $later->format(\DateTimeInterface::ATOM)));
	}

	/**
	 * Clearing and existing UI instants remain internal preservation paths, without a new public input format.
	 *
	 * @return void
	 */
	public function testNullAndExistingNativeInstantArePreserved(): void {
		$zone = new DateTimeZone('America/Sao_Paulo');
		self::assertNull(CardDueDate::forDeck(null, $zone));
		self::assertSame('2026-10-01T18:30:19Z', CardDueDate::forDeck('2026-10-01T18:30:19+00:00', $zone));
		self::assertSame('2026-10-01T18:30:19Z', CardDueDate::forDeck('2026-10-01T15:30:19-03:00', $zone));
	}

	/**
	 * The separate dueBefore filter remains date-only even though deadlines now accept time.
	 *
	 * @return void
	 */
	public function testDueBeforeStillRejectsAnHour(): void {
		$this->expectException(InvalidArgumentException::class);
		CardInput::dueBefore('2026-10-01T15:30:00Z');
	}

    public static function timed(): array {
        return [
            ['2026-10-01T15:30', 'America/Sao_Paulo', '2026-10-01T18:30:00Z'],
            ['2026-10-01T15:30:19', 'Asia/Tokyo', '2026-10-01T06:30:19Z'],
            ['2026-10-01T15:30:19+09:00', 'America/New_York', '2026-10-01T06:30:19Z'],
            ['2026-10-01T15:30-03:00', 'Asia/Tokyo', '2026-10-01T18:30:00Z'],
            ['2026-10-01T10:00-00:00', 'Asia/Tokyo', '2026-10-01T10:00:00Z'],
            ['2026-11-01T01:30', 'America/New_York', '2026-11-01T05:30:00Z'],
            ['2026-11-01T01:30-05:00', 'America/New_York', '2026-11-01T06:30:00Z'],
            ['2026-11-01T00:00', 'America/Havana', '2026-11-01T04:00:00Z'],
            ['2026-04-05T01:45', 'Australia/Lord_Howe', '2026-04-04T14:45:00Z'],
        ];
    }
    #[DataProvider('timed')]
    public function testExplicitTimesResolveToTheRequestedInstant(string $input, string $zone, string $expected): void {
        self::assertSame($input, CardInput::duedate($input, new DateTimeZone($zone)));
        self::assertSame($expected, CardDueDate::forDeck($input, new DateTimeZone($zone)));
    }
    public static function invalidTimes(): array {
        return [
            ['2026-03-08T02:30', 'America/New_York'],
            ['2026-09-06T00:00', 'America/Santiago'],
            ['2026-10-01T24:00', 'UTC'],
            ['2026-02-30T12:00Z', 'UTC'],
            ['2026-10-01T12:00:60Z', 'UTC'],
            ['2026-10-01T12:00+24:00', 'UTC'],
            ['2026-10-01 12:00', 'UTC'],
            ['2026-10-01T12:00:00.123Z', 'UTC'],
        ];
    }
    #[DataProvider('invalidTimes')]
    public function testInvalidOrNonexistentExplicitTimesAreRefused(string $input, string $zone): void {
        $this->expectException(InvalidArgumentException::class);
        CardInput::duedate($input, new DateTimeZone($zone));
    }
    public function testPlanDisambiguatesRepeatedMidnightAndShowsSecondsAndZone(): void {
        $view = \OCA\Mcp\Modules\Deck\Presentation\CardDueDateView::class;
        self::assertSame('2026-11-01 00:00 (America/Havana, UTC-04:00)', $view::formatPlan('2026-11-01T00:00-04:00', new DateTimeZone('America/Havana')));
        self::assertStringContainsString('15:30:19', $view::formatPlan('2026-10-01T15:30:19', new DateTimeZone('UTC')));
        self::assertSame('2026-10-01', $view::formatPlan('2026-10-01', new DateTimeZone('UTC')));
    }
}
