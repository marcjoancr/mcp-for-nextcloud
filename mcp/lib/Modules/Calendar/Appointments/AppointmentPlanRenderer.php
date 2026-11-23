<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Appointments;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;
use OCA\Mcp\Mcp\Confirmation\RendersPlans;
use Throwable;

final class AppointmentPlanRenderer implements RendersPlans
{
    public function renderPlan(string $tool, array $plan): ?string
    {
        try {
            $action = PlanText::inline((string)($plan['action'] ?? $tool));
            $before = $this->row(is_array($plan['before'] ?? null) ? $plan['before'] : null);
            $after = $this->row(is_array($plan['after'] ?? null) ? $plan['after'] : null);
            $scope = PlanText::inline((string)($plan['scope'] ?? ''));
            $consequence = PlanText::inline((string)($plan['consequence'] ?? ''));
            $lines = [Translator::t('Appointment configuration action: %s.', [$action])];
            if ($before !== null) { $lines[] = Translator::t('Current: %s.', [$before]); }
            if ($after !== null) { $lines[] = Translator::t('Proposed: %s.', [$after]); }
            if ($scope !== '') { $lines[] = $scope; }
            if ($consequence !== '') { $lines[] = $consequence; }
            $lines[] = Translator::t('Nothing changes until you confirm this exact plan.');
            return implode("\n\n", $lines);
        } catch (Throwable) {
            return null;
        }
    }

    private function row(?array $row): ?string
    {
        if ($row === null) { return null; }
        $parts = [];
        $labels = [
            'id' => 'ID', 'name' => 'Name', 'description' => 'Description', 'location' => 'Location',
            'visibility' => 'Visibility', 'calendar' => 'Calendar', 'durationMinutes' => 'Duration (minutes)',
            'increment' => 'Slot increment (minutes)', 'preparationDuration' => 'Preparation buffer (minutes)',
            'followupDuration' => 'Follow-up buffer (minutes)', 'buffer' => 'Buffer between bookings (minutes)',
            'dailyMax' => 'Daily booking limit', 'start' => 'Booking starts (UTC)', 'end' => 'Booking ends (UTC)',
            'futureLimit' => 'Future booking limit (days)',
        ];
        foreach ($labels as $key => $label) {
            if (!array_key_exists($key, $row)) { continue; }
            $value = $row[$key];
            if ($key === 'start' || $key === 'end') {
                $value = is_int($value) ? gmdate('Y-m-d H:i', $value) : 'Not set';
            } elseif ($value === null) {
                $value = 'Not set';
            }
            if (is_scalar($value)) { $parts[] = PlanText::inline($label) . ': ' . PlanText::inline((string)$value); }
        }
        $availability = $row['availability'] ?? null;
        if (is_array($availability)) {
            $schedule = [];
            foreach (is_array($availability['slots'] ?? null) ? $availability['slots'] : [] as $day => $slots) {
                if (!is_array($slots) || $slots === []) { continue; }
                $times = [];
                foreach (array_slice($slots, 0, 12) as $slot) {
                    if (is_array($slot) && is_string($slot['start'] ?? null) && is_string($slot['end'] ?? null)) {
                        $times[] = PlanText::inline($slot['start']) . '–' . PlanText::inline($slot['end']);
                    }
                }
                if ($times !== []) { $schedule[] = PlanText::inline((string)$day) . ' ' . implode(', ', $times); }
            }
            $zone = is_string($availability['timezoneId'] ?? null) ? $availability['timezoneId'] : '';
            if ($schedule !== []) { $parts[] = PlanText::inline('Weekly availability') . ': ' . PlanText::inline($zone) . ' ' . implode('; ', $schedule); }
        }
        return $parts === [] ? null : implode('; ', $parts);
    }
}
