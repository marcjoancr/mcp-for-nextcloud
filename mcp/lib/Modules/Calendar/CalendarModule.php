<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;
final class CalendarModule implements \OCA\Mcp\Mcp\Tools\ToolModule, \OCA\Mcp\Mcp\Confirmation\PreviewsWrites, \OCA\Mcp\Mcp\Confirmation\RendersPlans, \OCA\Mcp\Mcp\Guide\ToolGuideNotes
{
    public const APPOINTMENT_TOOLS = [
        'calendar_list_appointments' => true,
        'calendar_create_appointment' => true,
        'calendar_update_appointment' => true,
        'calendar_delete_appointment' => true,
        'calendar_list_appointment_bookings' => true,
    ];

    /**
     * @param array<string, \Closure(): Tool> $factories
     * @param (\Closure(): bool)|null $appointmentsAvailable
     */
    public function __construct(
        private array $factories,
        private \Closure $assertReferencesAvailable,
        private ?\Closure $appointmentsAvailable = null,
    )
    {
        $expected = array_keys(CalendarToolCatalog::definitions());
        if (array_diff($expected, array_keys($factories)) !== [] || array_diff(array_keys($factories), $expected) !== []) {
            throw new \LogicException('Factories must match the catalog exactly');
        }
        foreach ($factories as $factory) {
            if (!$factory instanceof \Closure) {
                throw new \LogicException('Factories must be closures');
            }
        }
    }
    public function definitions(): array
    {
        $definitions = CalendarToolCatalog::definitions();
        if (!isset($this->appointmentsAvailable) || !($this->appointmentsAvailable)()) {
            $definitions = array_diff_key($definitions, self::APPOINTMENT_TOOLS);
        }
        return array_values($definitions);
    }
    private function resolve(string $name): Tool
    {
        $tool = $this->factories[$name]();
        if (!$tool instanceof Tool || $tool::definition()->name !== $name) {
            throw new \LogicException('Factory returned a different tool');
        }
        if (!in_array(CalendarToolCatalog::definitions()[$name]['operation'], ['read', 'availability'], true) && !$tool instanceof WriteTool) {
            throw new \LogicException('Writes must implement WriteTool');
        }
        return $tool;
    }
    private function known(string $name): void
    {
        if (!isset($this->factories[$name]) || (isset(self::APPOINTMENT_TOOLS[$name])
            && (!isset($this->appointmentsAvailable) || !($this->appointmentsAvailable)()))) {
            throw new \InvalidArgumentException('Unknown tool');
        }
    }
    public function preview(
        string $name,
        array $arguments,
        string $userId,
    ): array
    {
        ($this->assertReferencesAvailable)($userId);
        $this->known($name);
        if (in_array(CalendarToolCatalog::definitions()[$name]['operation'], ['read', 'availability'], true)) {
            throw new \InvalidArgumentException('Unknown tool');
        }
        return $this->resolve($name)->preview($arguments, new ToolContext($userId));
    }
    public function call(
        string $name,
        array $arguments,
        string $userId,
    ): array
    {
        ($this->assertReferencesAvailable)($userId);
        $this->known($name);
        try {
            return $this->resolve($name)->execute($arguments, new ToolContext($userId));
        } catch (Execution\CalendarException|\OCA\Mcp\Platform\Dav\CalDav\CalDavFailure $e) {
            return \OCA\Mcp\Mcp\Tools\Results\ToolResult::error($e->getMessage());
        } catch (Execution\CalendarArgumentException|\OCA\Mcp\Platform\Dav\Icalendar\DateArgumentException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('Calendar backend failure', 0, $e);
        }
    }
    public function guideNotes(): array
    {
        return [
            'Calendars are addressed by path: calendar_list_calendars gives the paths of this user. Events are ' . 'addressed by uid, and calendar_list_events gives the uids of a period.',
            'Use calendar_search_events or calendar_read_event to find details you may see. calendar_find_slots combines the organizer’s free ranges with native free/busy for internal attendees. An unverified attendee does not count as available.',
            'The separate external-attendee tools use email addresses selected by the user. Availability for those addresses is unknown. calendar_respond_to_invitation changes only the acting attendee on their own calendar and requires the preview ETag.',
            'List native rooms and resources with calendar_list_resources and read free/busy with calendar_check_resource_availability. A resource booking is a separate scheduling request on an existing non-recurring event on your calendar. Review the resource response separately from the event update.',
            'On supported Calendar app versions the appointment tools manage native booking links on your own calendars. Private configurations remain bookable by anyone with the URL. Do not reveal a link unless the user asks you to share it.',
            'calendar_check_availability reads busy intervals and free ranges in an explicit UTC window of at most 31 days. It checks at most 20 visible calendars and returns no event titles. Private or unreadable event data, a scan limit, or any incomplete scan makes the whole answer unavailable rather than claiming a time is free.',
            'Every write answers with a plan first: what it would change, the etag of the object as it is now, and ' . 'which invitations would go out. Nothing is written until the very same call is repeated with ' . 'confirm: true, so show the plan to the user and wait for an explicit yes.',
            'The plan of a create, update (when the time or the guests change), move or transfer can carry warnings: a ' . 'collision with another event of the target calendar, a participant who is busy or whose agenda could ' . 'not be checked, and a calendar that is not shared with the participants. Each warning has a type ' . '(collision, busy, unverifiable, calendarNotShared) and a message to read to the user. They never block: ' . 'tell the user and repeat the call with confirm: true only if the answer is still yes. When ' . 'sharedCalendars lists calendars the participants already see and suggestedCalendar is set, offer it; ' . 'when there are several, ask the user which one to use and repeat the call with that calendar. Other ' . 'people\'s appointments are only ever reported as busy, never with their content. When an update ' . 'moves an event, the event\'s own slot is left out of the availability check of the guests it already ' . 'has (their copy of the invitation would otherwise make them busy); the collision check always leaves ' . 'the event itself out. An appointment of a guest sitting entirely inside the old slot is not reported.',
            'Dates and times arrive as they will be stored, and are read back in the timezone of the account; a ' . 'recurring event is expanded into its occurrences before it reaches the user.',
            'send_invitations hands the invitation to the CalDAV scheduling of the server. The plan and the result ' . 'say whether the server has e-mail invitations switched on (imipEnabled); when it is off, say so to ' . 'the user instead of claiming an e-mail was sent.',
            'A calendar owned by somebody else (a share) needs confirm_shared after asking the user, and a transfer ' . 'moves the event there and removes it from here.',
            'A deleted event goes to the Nextcloud trash bin of the calendar; with retention at 0 the deletion is ' . 'refused rather than permanent.',
            'Filter calendar_search_events by participation_status to find your unanswered invitations. Review calendar_import_ics to see duplicate events and excluded invitation, reminder and attachment data. Use calendar_set_event_reminders for display alarms before the event start.',
            'The writing tools appear only when the administrator granted them to this user; a module showing reads ' . 'only means the writing grants are off.',
        ];
    }
    public function renderPlan(string $name, array $plan): ?string
    {
        return (new Presentation\CalendarPlanRenderer())->renderPlan($name, $plan);
    }
}
