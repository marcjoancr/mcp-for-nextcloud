<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Trash;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventBuilder;
use OCA\Mcp\Modules\Calendar\Events\EventMapper;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Platform\Dav\CalDav\CalendarTrashStore;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess;

/** Confirms and restores only actor-authorized native trash entries; no iTIP scheduling is requested by native restore. */
final class CalendarTrashRestorer
{
    public const TOOL = 'calendar_restore_trashed_event';
    public function __construct(
        private ScopedCollectionAccess $access,
        private ObjectStore $objects,
        private CalendarTrashStore $trash,
        private EventRepository $events,
        private Classification $classification,
        private PlanState $states,
        private EventBuilder $builder = new EventBuilder(),
    ) {}

    /** @return array<string,mixed> */
    private function prepare(array $arguments, string $actor): array
    {
        $id = $arguments['object_id'] ?? null;
        if (!is_int($id) || $id < 1) throw new \InvalidArgumentException('object_id must be a positive integer.');
        $principal = 'principals/users/' . $actor;
        $row = $this->trash->deletedByIdForPrincipal($id, $principal);
        if ($row === null || !isset($row['calendarid'], $row['calendardata'])) throw new ToolFailure('The trashed calendar event is not available.');
        $calendar = null;
        foreach ($this->access->visible($actor) as $candidate) if ($candidate->id === (int)$row['calendarid']) { $calendar = $candidate; break; }
        if ($calendar === null || $calendar->ownerId !== $actor || !$calendar->writable) throw new ToolFailure('The trashed calendar event is not available.');
        $vcalendar = $this->events->parse((string)$row['calendardata']);
        $event = null;
        if ($vcalendar !== null) {
            foreach ($vcalendar->select('VEVENT') as $component) {
                if (!isset($component->{'RECURRENCE-ID'})) { $event = $component; break; }
            }
        }
        if ($vcalendar === null || $event === null || !isset($event->UID) || !isset($event->DTSTART)) throw new ToolFailure('The trashed calendar event cannot be restored.');
        $visibility = $this->classification->visibilityOf($event, $vcalendar, $calendar, $actor);
        if ($visibility === Classification::HIDDEN) throw new ToolFailure('The trashed calendar event is not available.');
        $uri = (string)($row['uri'] ?? '');
        $targetUri = \OCA\Mcp\Platform\Dav\CalDav\TrashedObject::originalUri($uri) ?? '';
        if ($targetUri === '' || $targetUri === $uri) throw new ToolFailure('The trashed calendar event cannot be restored.');
        $existing = $this->objects->object($calendar->id, $targetUri);
        if ($existing !== null && !$existing['deleted']) throw new ToolFailure('A live event already uses the original calendar object name.');
        ['start'=>$start,'end'=>$end,'allDay'=>$allDay] = $this->builder->timing($event);
        $shown = ['object_id'=>$id,'calendarId'=>$calendar->id,'calendar'=>$calendar->path,'etag'=>(string)($row['etag'] ?? ''),'owner'=>$calendar->ownerPrincipal,'targetUri'=>$targetUri,'targetEtag'=>$existing['etag'] ?? null];
        return $shown + [
            'summary'=>$visibility === Classification::BUSY ? EventMapper::BUSY_SUMMARY : (string)($event->SUMMARY ?? ''),
            'start'=>$allDay ? $start->format('Y-m-d') : $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
            'end'=>$allDay ? $end->format('Y-m-d') : $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
            'allDay'=>$allDay,'action'=>'restore_calendar_event','scheduling'=>['requested'=>false,'sent'=>false],
            PlanState::ARGUMENT=>$this->states->of(self::TOOL,$actor,$shown),
        ];
    }

    public function preview(array $arguments, string $actor): array { return $this->prepare($arguments,$actor); }

    public function restore(array $arguments, string $actor): array
    {
        PlanState::require($arguments);
        $plan = $this->prepare($arguments,$actor);
        if (!PlanState::matches($plan[PlanState::ARGUMENT],$arguments)) {
            throw new PlanChanged($this->preview($arguments,$actor) + ['warning'=>'The trashed event changed after its preview. Review the updated plan.']);
        }
        $row = $this->trash->deletedByIdForPrincipal((int)$plan['object_id'],'principals/users/' . $actor);
        if ($row === null || (string)($row['etag'] ?? '') !== $plan['etag']) throw new PlanChanged($this->preview($arguments,$actor) + ['warning'=>'The trashed event changed after its preview. Review the updated plan.']);
        // Core restoreCalendarObject has no If-Match parameter. It rechecks URI collision inside its transaction;
        // this second explicit live-row check narrows the window and the native atomic collision check is decisive.
        $currentTarget = $this->objects->object((int)$plan['calendarId'],(string)$plan['targetUri']);
        if ($currentTarget !== null && !$currentTarget['deleted']) throw new ToolFailure('A live event now uses the original calendar object name.');
        try { $this->trash->restoreByIdForPrincipal((int)$plan['object_id'],'principals/users/' . $actor); }
        catch (\Throwable $error) { throw new ToolFailure('The event could not be restored; the native calendar state was not verified.',0,$error); }
        $restored = $this->objects->object((int)$plan['calendarId'],(string)$plan['targetUri']);
        $stillDeleted = $this->trash->deletedByIdForPrincipal((int)$plan['object_id'],'principals/users/' . $actor);
        if ($restored === null || $restored['deleted'] || $stillDeleted !== null) throw new ToolFailure('The restore request completed but the calendar state could not be verified.');
        return ToolResult::json(['calendar'=>$plan['calendar'],'object_id'=>$plan['object_id'],'summary'=>$plan['summary'],'restored'=>true,'etag'=>$restored['etag'],'scheduling'=>['requested'=>false,'sent'=>false,'reason'=>'Native trash restore does not send an invitation.']]);
    }
}
