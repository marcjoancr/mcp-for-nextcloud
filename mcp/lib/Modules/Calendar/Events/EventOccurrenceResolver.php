<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Events;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/** Resolves an occurrence by its original recurrence identity, including exceptions moved to another time. */
final class EventOccurrenceResolver
{
    public function __construct(private EventExpander $expander, private EventDates $dates, private EventBuilder $builder) {}
    public function find(VCalendar $calendar, string $recurrenceId): ?VEvent
    {
        $target=$this->parseId($calendar,$recurrenceId);
        foreach ($calendar->select('VEVENT') as $event) {
            if (isset($event->{'RECURRENCE-ID'}) && $this->same($event->{'RECURRENCE-ID'},$target,$event->DTSTART->hasTime())) return $event;
        }
        $from=$target; $to=$target->modify('+1 second');
        foreach ($this->expander->occurrences($calendar,$from,$to) as $occurrence) {
            $id=$occurrence['recurrenceId']??null;
            if ($id instanceof DateTimeImmutable && $id->getTimestamp()===$target->getTimestamp()) return $occurrence['event'];
        }
        return null;
    }
    /** Returns an existing override or adds a copy of the series master for this occurrence. */
    public function ensureOverride(VCalendar $calendar, VEvent $master, string $recurrenceId): VEvent
    {
        $target=$this->parseId($calendar,$recurrenceId);
        foreach ($calendar->select('VEVENT') as $existing) {
            if (isset($existing->{'RECURRENCE-ID'}) && $this->same($existing->{'RECURRENCE-ID'},$target,$master->DTSTART->hasTime())) return $existing;
        }
        $event=$this->find($calendar,$recurrenceId);
        if ($event === null) throw CalendarException::notFound();
        $override=clone $master;
        unset($override->RRULE,$override->RDATE,$override->EXDATE,$override->{'RECURRENCE-ID'});
        $this->setRecurrenceId($override,$master,$recurrenceId);
        $timing=$this->builder->timing($event);
        $this->builder->setTiming($override,new EventTiming($timing['start'],$timing['end'],$timing['allDay'],$timing['timeZone']));
        $calendar->add($override);
        return $override;
    }
    /** Adds one EXDATE to the master and removes a matching exception without changing adjacent dates. */
    public function excludeOccurrence(VCalendar $calendar, VEvent $master, string $recurrenceId): void
    {
        $target=$this->parseId($calendar,$recurrenceId);
        if ($this->find($calendar,$recurrenceId)===null) throw CalendarException::notFound();
        $allDay=!$master->DTSTART->hasTime();
        if ($allDay) {
            $master->add('EXDATE',$this->dates->day($recurrenceId,'recurrence_id'),['VALUE'=>'DATE']);
        } else {
            $date=$this->dates->parse($recurrenceId,'recurrence_id');
            $tzid=$master->DTSTART['TZID']??null;
            if ($tzid!==null) {
                $zone=$this->dates->timeZone((string)$tzid);
                $master->add('EXDATE',$date->setTimezone($zone),['TZID'=>$zone->getName()]);
            } else $master->add('EXDATE',$date->setTimezone(new DateTimeZone('UTC')));
        }
        foreach ($calendar->select('VEVENT') as $component) {
            if (isset($component->{'RECURRENCE-ID'}) && $this->same($component->{'RECURRENCE-ID'},$target,$master->DTSTART->hasTime())) $calendar->remove($component);
        }
    }

    private function setRecurrenceId(VEvent $override,VEvent $master,string $input): void
    {
        $start=$master->DTSTART;
        if (!$start->hasTime()) {
            if (!$this->dates->isDateOnly($input)) throw new CalendarArgumentException('recurrence_id for an all-day series must be YYYY-MM-DD.','recurrence_id','invalid');
            $override->add('RECURRENCE-ID',$this->dates->day($input,'recurrence_id'),['VALUE'=>'DATE']);
            return;
        }
        if ($this->dates->isDateOnly($input)) throw new CalendarArgumentException('recurrence_id for a timed series must include a timestamp.','recurrence_id','invalid');
        $date=$this->dates->parse($input,'recurrence_id');
        $tzid=$start['TZID']??null;
        if ($tzid!==null) {
            $zone=$this->dates->timeZone((string)$tzid);
            $override->add('RECURRENCE-ID',$date->setTimezone($zone),['TZID'=>$zone->getName()]);
        } else $override->add('RECURRENCE-ID',$date->setTimezone(new DateTimeZone('UTC')));
    }
    private function parseId(VCalendar $calendar,string $id): DateTimeImmutable
    {
        $master=null; foreach ($calendar->select('VEVENT') as $event) if (!isset($event->{'RECURRENCE-ID'})) { $master=$event; break; }
        if (!$master instanceof VEvent || !isset($master->DTSTART)) throw CalendarException::notFound();
        $allDay=!$master->DTSTART->hasTime();
        if ($allDay!==$this->dates->isDateOnly($id)) throw new CalendarArgumentException($allDay?'recurrence_id for an all-day series must be YYYY-MM-DD.':'recurrence_id for a timed series must include a timestamp.','recurrence_id','invalid');
        return $allDay ? $this->dates->day($id,'recurrence_id') : $this->dates->parse($id,'recurrence_id');
    }
    private function same($property,DateTimeImmutable $target,bool $timed): bool
    {
        try { $date=$property->getDateTime(new DateTimeZone('UTC')); return $timed ? $date->getTimestamp()===$target->getTimestamp() : $date->format('Y-m-d')===$target->format('Y-m-d'); }
        catch (\Throwable) { return false; }
    }
}
