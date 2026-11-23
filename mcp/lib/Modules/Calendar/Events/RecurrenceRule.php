<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Events;
use DateTimeZone;
use DateTimeImmutable;
use Sabre\VObject\Component\VEvent;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;

/** Builds the small, finite recurrence vocabulary exposed to tools. */
final class RecurrenceRule
{
    private const DAYS = ['MO','TU','WE','TH','FR','SA','SU'];
    public function __construct(private EventDates $dates) {}
    /** @param array<string,mixed> $input */
    public function apply(VEvent $event, array $input, EventTiming $timing): void
    {
        $allowed=['frequency','interval','byDay','until'];
        if (array_diff(array_keys($input),$allowed)!==[] || !isset($input['frequency'],$input['until']) || !is_string($input['frequency']) || !is_string($input['until'])) throw new CalendarArgumentException('recurrence requires frequency and until.', 'recurrence', 'invalid');
        $frequency=strtoupper($input['frequency']);
        if (!in_array($frequency,['WEEKLY','MONTHLY'],true)) throw new CalendarArgumentException('frequency must be WEEKLY or MONTHLY.', 'recurrence.frequency', 'invalid');
        $interval=$input['interval']??1;
        if (!is_int($interval)||$interval<1||$interval>4) throw new CalendarArgumentException('interval must be between 1 and 4.', 'recurrence.interval', 'out of range');
        $until=$this->dates->day($input['until'],'recurrence.until');
        $zone=$timing->timeZone??new DateTimeZone('UTC');
        $first=$timing->start->setTimezone($zone)->format('Y-m-d');
        $last=$until->format('Y-m-d');
        $firstDay=new DateTimeImmutable($first.' 00:00:00',new DateTimeZone('UTC'));
        $lastDay=new DateTimeImmutable($last.' 00:00:00',new DateTimeZone('UTC'));
        if ($lastDay<$firstDay || $lastDay->getTimestamp()-$firstDay->getTimestamp()>366*86400) throw new CalendarArgumentException('recurrence until must be between the event date and one year later.', 'recurrence.until', 'out of range');
        $parts=['FREQ='.$frequency];
        if ($interval!==1) $parts[]='INTERVAL='.$interval;
        if ($frequency==='WEEKLY') {
            $byDay=$input['byDay']??[self::DAYS[(int)$timing->start->setTimezone($zone)->format('N')-1]];
            if (!is_array($byDay)||!array_is_list($byDay)||$byDay===[]||count($byDay)>7) throw new CalendarArgumentException('byDay must contain unique weekday codes.', 'recurrence.byDay', 'invalid');
            $normal=[];
            foreach ($byDay as $day) { if (!is_string($day)||!in_array(strtoupper($day),self::DAYS,true)) throw new CalendarArgumentException('byDay uses MO, TU, WE, TH, FR, SA or SU.', 'recurrence.byDay', 'invalid'); $normal[]=strtoupper($day); }
            if (count($normal)!==count(array_unique($normal))) throw new CalendarArgumentException('byDay values must be unique.', 'recurrence.byDay', 'duplicate');
            $ordered=array_values(array_filter(self::DAYS,static fn(string $day):bool=>in_array($day,$normal,true)));
            $parts[]='BYDAY='.implode(',',$ordered);
        } else {
            if (array_key_exists('byDay',$input)) throw new CalendarArgumentException('byDay is only supported for weekly recurrence.', 'recurrence.byDay', 'unsupported');
            $parts[]='BYMONTHDAY='.(int)$timing->start->setTimezone($zone)->format('j');
        }
        if ($timing->allDay) $parts[]='UNTIL='.$until->format('Ymd');
        else $parts[]='UNTIL='.(new DateTimeImmutable($last.' 23:59:59',$zone))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        unset($event->RRULE);
        $event->add('RRULE',implode(';',$parts));
    }
}
