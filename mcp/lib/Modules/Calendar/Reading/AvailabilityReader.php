<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Reading;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventDates;
use OCA\Mcp\Modules\Calendar\Events\EventExpander;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess;
use OCA\Mcp\Platform\Dav\Collections\Collection;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use Throwable;

/**
 * Computes availability only when every matching calendar object can be scanned and classified safely.
 * No event content is returned; shared-calendar events classified as hidden fail the whole request closed.
 */
final class AvailabilityReader
{
	public const MAX_WINDOW_SECONDS = 31 * 86400;
	public const MAX_CALENDARS = 20;
	public const MAX_OBJECTS_PER_CALENDAR = 2000;
	public const MAX_OCCURRENCES = 10000;
	public const DEFAULT_MIN_DURATION_MINUTES = 30;
	public const MIN_DURATION_MINUTES = 5;
	public const MAX_DURATION_MINUTES = 1440;

	public function __construct(
		private ScopedCollectionAccess $access,
		private ObjectStore $store,
		private EventRangeQuery $ranges,
		private EventRepository $events,
		private Classification $classification,
		private EventExpander $expander,
		private EventDates $dates,
	) {
	}

	/**
	 * @param array{from:string, to:string, calendars?:list<string>, min_duration_minutes?:int} $arguments
	 * @param string $userId authenticated UID
	 * @return array<string, mixed> busy intervals and free ranges, or a generic fail-closed error
	 */
	public function execute(array $arguments, string $userId): array
	{
		[$from, $to] = $this->window($arguments['from'] ?? null, $arguments['to'] ?? null);
		$minimum = $arguments['min_duration_minutes'] ?? self::DEFAULT_MIN_DURATION_MINUTES;
		if (!is_int($minimum) || $minimum < self::MIN_DURATION_MINUTES || $minimum > self::MAX_DURATION_MINUTES) {
			throw new CalendarArgumentException(Translator::t('min_duration_minutes must be between 5 and 1440.'), 'min_duration_minutes', Translator::t('must be between 5 and 1440'));
		}

		$calendars = $this->selectedCalendars($arguments['calendars'] ?? null, $userId);
		if ($calendars === [] || count($calendars) > self::MAX_CALENDARS) {
			throw CalendarException::limit(Translator::t('Select between 1 and 20 accessible calendars.'));
		}

		try {
			$busy = [];
			$occurrenceCount = 0;
			foreach ($calendars as $calendar) {
				$uris = $this->ranges->eventUrisInRange($calendar->id, $from, $to);
				if (count($uris) > self::MAX_OBJECTS_PER_CALENDAR) {
					throw new \RuntimeException('Calendar scan limit exceeded');
				}
				$rows = $this->store->objects($calendar->id, $uris);
				if (count($rows) !== count(array_unique($uris))) {
					throw new \RuntimeException('Calendar scan changed during read');
				}
				foreach ($rows as $row) {
					if (($row['deleted'] ?? true) || !is_string($row['data'] ?? null)) {
						throw new \RuntimeException('Calendar object is incomplete');
					}
					$vcalendar = $this->events->parse($row['data']);
					if ($vcalendar === null) {
						throw new \RuntimeException('Calendar object cannot be parsed');
					}
					$this->assertCompleteCalendar($vcalendar);
					foreach ($this->expander->occurrences($vcalendar, $from, $to) as $occurrence) {
						if (++$occurrenceCount > self::MAX_OCCURRENCES) {
							throw new \RuntimeException('Calendar occurrence scan limit exceeded');
						}
						$event = $occurrence['event'];
						$visibility = $this->classification->visibilityOf($event, $vcalendar, $calendar, $userId);
						if ($visibility === Classification::HIDDEN) {
							throw new \RuntimeException('Calendar availability is hidden by event classification');
						}
						if (strtoupper((string) ($event->STATUS ?? '')) === 'CANCELLED'
							|| strtoupper((string) ($event->TRANSP ?? 'OPAQUE')) === 'TRANSPARENT') {
							continue;
						}
						$start = $this->utc($occurrence['start']);
						$end = $this->utc($occurrence['end']);
						if ($start >= $end) {
							throw new \RuntimeException('Calendar event has an invalid duration');
						}
						$start = max($start, $from);
						$end = min($end, $to);
						if ($start < $end) {
							$busy[] = ['calendar' => $calendar->path, 'start' => $start, 'end' => $end];
						}
					}
				}
			}
		} catch (Throwable) {
			return ToolResult::error(Translator::t('Calendar availability could not be verified.'));
		}

		$conflicts = $this->conflicts($busy);
		$busy = $this->mergeByCalendar($busy);
		$free = $this->freeRanges($busy, $from, $to, $minimum * 60);
		return ToolResult::json([
			'from' => $this->format($from),
			'to' => $this->format($to),
			'calendars' => array_map(static fn (Collection $calendar): string => $calendar->path, $calendars),
			'busy' => array_map(fn (array $interval): array => [
				'calendar' => $interval['calendar'],
				'start' => $this->format($interval['start']),
				'end' => $this->format($interval['end']),
			], $busy),
			'conflicts' => array_map(fn (array $interval): array => [
				'calendars' => $interval['calendars'],
				'start' => $this->format($interval['start']),
				'end' => $this->format($interval['end']),
				'overlappingEvents' => $interval['overlappingEvents'],
			], $conflicts),
			'free' => array_map(fn (array $interval): array => [
				'start' => $this->format($interval['start']),
				'end' => $this->format($interval['end']),
			], $free),
		]);
	}

	/** @return array{0:DateTimeImmutable,1:DateTimeImmutable} */
	private function window(mixed $from, mixed $to): array
	{
		if (!is_string($from) || !is_string($to)) {
			throw new CalendarArgumentException(Translator::t('from and to are required.'), 'from', Translator::t('required'));
		}
		[$start, $end] = $this->dates->window($from, $to, new DateTimeImmutable('now', new DateTimeZone('UTC')));
		if ($end->getTimestamp() - $start->getTimestamp() > self::MAX_WINDOW_SECONDS) {
			throw CalendarException::limit(Translator::t('Availability window exceeds the limit of 31 days.'));
		}
		return [$start, $end];
	}

	/** @param mixed $paths @return list<Collection> */
	private function selectedCalendars(mixed $paths, string $userId): array
	{
		if ($paths === null) {
			return $this->access->visible($userId);
		}
		if (!is_array($paths) || !array_is_list($paths) || count($paths) > self::MAX_CALENDARS || count($paths) === 0) {
			throw new CalendarArgumentException(Translator::t('calendars must contain between 1 and 20 calendar paths.'), 'calendars', Translator::t('provide between 1 and 20 accessible calendars'));
		}
		$calendars = [];
		$seen = [];
		foreach ($paths as $path) {
			if (!is_string($path) || isset($seen[$path])) {
				throw new CalendarArgumentException(Translator::t('calendars must contain unique calendar paths.'), 'calendars', Translator::t('provide unique accessible calendars'));
			}
			$seen[$path] = true;
			$calendars[] = $this->access->resolve($userId, $path);
		}
		return $calendars;
	}

	/** @param list<array{calendar:string,start:DateTimeImmutable,end:DateTimeImmutable}> $busy @return list<array{calendar:string,start:DateTimeImmutable,end:DateTimeImmutable}> */
	private function mergeByCalendar(array $busy): array
	{
		usort($busy, static fn (array $a, array $b): int => [$a['calendar'], $a['start'], $a['end']] <=> [$b['calendar'], $b['start'], $b['end']]);
		$merged = [];
		foreach ($busy as $interval) {
			$last = array_key_last($merged);
			if ($last !== null && $merged[$last]['calendar'] === $interval['calendar'] && $interval['start'] <= $merged[$last]['end']) {
				if ($interval['end'] > $merged[$last]['end']) {
					$merged[$last]['end'] = $interval['end'];
				}
				continue;
			}
			$merged[] = $interval;
		}
		return $merged;
	}

	private function assertCompleteCalendar(\Sabre\VObject\Component\VCalendar $vcalendar): void
	{
		$events = $vcalendar->select('VEVENT');
		if ($events === []) {
			throw new \RuntimeException('Calendar query returned an object without VEVENT data');
		}
		foreach ($events as $event) {
			if (!isset($event->UID) || trim((string) $event->UID) === '' || !isset($event->DTSTART)) {
				throw new \RuntimeException('Calendar event is missing its identity or start');
			}
			if (isset($event->DTEND) && isset($event->DURATION)) {
				throw new \RuntimeException('Calendar event has conflicting end and duration');
			}
		}
	}

	/**
	 * @param list<array{calendar:string,start:DateTimeImmutable,end:DateTimeImmutable}> $busy
	 * @return list<array{calendars:list<string>,start:DateTimeImmutable,end:DateTimeImmutable,overlappingEvents:int}>
	 */
	private function conflicts(array $busy): array
	{
		$points = [];
		foreach ($busy as $index => $interval) {
			$points[$interval['start']->getTimestamp()]['starts'][$index] = $interval['calendar'];
			$points[$interval['end']->getTimestamp()]['ends'][$index] = $interval['calendar'];
		}
		ksort($points, SORT_NUMERIC);
		$active = [];
		$previous = null;
		$conflicts = [];
		foreach ($points as $timestamp => $point) {
			$timestamp = (int) $timestamp;
			if ($previous !== null && $timestamp > $previous && count($active) >= 2) {
				$calendars = array_values(array_unique(array_values($active)));
				sort($calendars, SORT_STRING);
				$last = array_key_last($conflicts);
				if ($last !== null
					&& $conflicts[$last]['end']->getTimestamp() === $previous
					&& $conflicts[$last]['calendars'] === $calendars
					&& $conflicts[$last]['overlappingEvents'] === count($active)) {
					$conflicts[$last]['end'] = new DateTimeImmutable('@' . $timestamp);
				} else {
					$conflicts[] = [
						'calendars' => $calendars,
						'start' => new DateTimeImmutable('@' . $previous),
						'end' => new DateTimeImmutable('@' . $timestamp),
						'overlappingEvents' => count($active),
					];
				}
			}
			foreach ($point['ends'] ?? [] as $index => $_calendar) {
				unset($active[$index]);
			}
			foreach ($point['starts'] ?? [] as $index => $calendar) {
				$active[$index] = $calendar;
			}
			$previous = $timestamp;
		}
		return $conflicts;
	}

	/** @param list<array{calendar:string,start:DateTimeImmutable,end:DateTimeImmutable}> $busy @return list<array{start:DateTimeImmutable,end:DateTimeImmutable}> */
	private function freeRanges(array $busy, DateTimeImmutable $from, DateTimeImmutable $to, int $minimumSeconds): array
	{
		$all = array_map(static fn (array $interval): array => ['start' => $interval['start'], 'end' => $interval['end']], $busy);
		usort($all, static fn (array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);
		$unified = [];
		foreach ($all as $interval) {
			$last = array_key_last($unified);
			if ($last !== null && $interval['start'] <= $unified[$last]['end']) {
				if ($interval['end'] > $unified[$last]['end']) {
					$unified[$last]['end'] = $interval['end'];
				}
			} else {
				$unified[] = $interval;
			}
		}

		$free = [];
		$cursor = $from;
		foreach ($unified as $interval) {
			if ($interval['start']->getTimestamp() - $cursor->getTimestamp() >= $minimumSeconds) {
				$free[] = ['start' => $cursor, 'end' => $interval['start']];
			}
			if ($interval['end'] > $cursor) {
				$cursor = $interval['end'];
			}
		}
		if ($to->getTimestamp() - $cursor->getTimestamp() >= $minimumSeconds) {
			$free[] = ['start' => $cursor, 'end' => $to];
		}
		return $free;
	}

	private function utc(DateTimeImmutable $value): DateTimeImmutable
	{
		return $value->setTimezone(new DateTimeZone('UTC'));
	}

	private function format(DateTimeImmutable $value): string
	{
		return $this->utc($value)->format('Y-m-d\TH:i:s\Z');
	}
}
