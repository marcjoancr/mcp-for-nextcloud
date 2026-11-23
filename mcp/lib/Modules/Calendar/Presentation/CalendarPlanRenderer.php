<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Calendar\Appointments\AppointmentPlanRenderer;
use OCA\Mcp\Modules\Calendar\CalendarModule;
use OCA\Mcp\Mcp\Confirmation\PlanText;
use OCA\Mcp\Mcp\Confirmation\RendersPlans;
use Throwable;
final class CalendarPlanRenderer implements RendersPlans
{
    public function __construct(private ?string $forcedLocale = null)
    {
    }
    public function renderPlan(string $tool, array $plan): ?string
    {
        try {
            if (isset(CalendarModule::APPOINTMENT_TOOLS[$tool])) {
                return (new AppointmentPlanRenderer())->renderPlan($tool, $plan);
            }
            if ($tool === 'calendar_import_ics') {
                return $this->renderImport($plan);
            }
            if ($tool === 'calendar_set_event_reminders') {
                return $this->renderReminders($plan);
            }
            if (in_array($tool, ['calendar_create_calendar', 'calendar_share_calendar', 'calendar_restore_trashed_event'], true)) {
                $renderer = new CalendarCollectionsPlanRenderer();
                return match ($tool) {
                    'calendar_create_calendar' => $renderer->create($plan),
                    'calendar_share_calendar' => $renderer->share($plan),
                    'calendar_restore_trashed_event' => $renderer->restore($plan),
                };
            }
            $action = (string) ($plan['action'] ?? $tool);
            return match ($action) {
                'calendar_create_event', 'calendar_create_event_with_external_attendees' => (new EventChangeRenderer(new CalendarPlanText($this->forcedLocale)))->renderCreate($plan),
                'calendar_update_event', 'calendar_update_event_with_external_attendees', 'calendar_reserve_resource' => (new EventChangeRenderer(new CalendarPlanText($this->forcedLocale)))->renderUpdate($plan),
                'calendar_move_event' => (new EventRelocationRenderer(new CalendarPlanText($this->forcedLocale)))->renderMove($plan),
                'calendar_transfer_event' => (new EventRelocationRenderer(new CalendarPlanText($this->forcedLocale)))->renderTransfer($plan),
                'calendar_respond_to_invitation' => $this->renderResponse($plan),
                'calendar_delete_event' => (new EventDeletionRenderer(new CalendarPlanText($this->forcedLocale)))->renderDelete($plan),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function renderImport(array $plan): string
    {
        $lines = [Translator::t('Import events from %1$s into %2$s.', [
            PlanText::inline((string)$plan['source']), PlanText::inline((string)$plan['calendar']),
        ])];
        $status = [
            'create' => Translator::t('Create'),
            'already_present' => Translator::t('Already present'),
            'conflict' => Translator::t('Conflict'),
        ];
        $events = [];
        foreach ($plan['events'] as $event) {
            $events[] = '- ' . PlanText::inline((string)($event['summary'] ?: $event['uid'])) . ' · ' . ($status[$event['status']] ?? '');
        }
        $lines[] = implode("\n", $events);
        $lines[] = Translator::t('Imports omit organizers, attendees, reminders and attachments.');
        return implode("\n\n", $lines);
    }

    private function renderReminders(array $plan): string
    {
        $minutes = static fn (array $values): string => $values === []
            ? Translator::t('None')
            : implode(', ', array_map('intval', $values));
        $lines = [
            Translator::t('Edit reminders for %s.', [PlanText::inline((string)$plan['summary'])]),
            Translator::t('Minutes before start change from %1$s to %2$s.', [$minutes($plan['before']), $minutes($plan['after'])]),
        ];
        foreach (($plan['shared'] ?? []) as $notice) {
            $lines[] = PlanText::inline((string)$notice['message']);
        }
        return implode("\n\n", $lines);
    }

    private function renderResponse(array $plan): string
    {
        $change = Translator::t('Invitation response changes from %1$s to %2$s.', [
            PlanText::inline((string)$plan['before']), PlanText::inline((string)$plan['after']),
        ]);
        $event = Translator::t('Event %1$s, organized by %2$s.', [
            PlanText::inline((string)$plan['summary']), PlanText::inline((string)$plan['organizer']),
        ]);
        $effect = $plan['willReply']
            ? Translator::t('Nextcloud will be asked to send your response to the organizer.')
            : Translator::t('Your response is already recorded. No reply will be scheduled.');
        return $event . "\n\n" . $change . "\n\n" . $effect;
    }
}
