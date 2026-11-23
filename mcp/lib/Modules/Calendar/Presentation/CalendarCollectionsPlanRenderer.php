<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Safe, inert Markdown for personal calendar creation, direct shares and trash restoration plans. */
final class CalendarCollectionsPlanRenderer
{
    public function create(array $plan): ?string
    {
        $name = $this->inline($plan['displayName'] ?? null);
        $path = $this->inline($plan['path'] ?? null);
        if ($name === '' || $path === '') return null;
        return Translator::t('Create personal calendar **%s**.', [$name]) . "\n" . Translator::t('- Calendar: %s', [$path]);
    }

    public function share(array $plan): ?string
    {
        $calendar = $this->inline($plan['calendar'] ?? null);
        $recipient = $this->inline($plan['displayName'] ?? $plan['shareWith'] ?? null);
        $access = $this->inline($plan['access'] ?? null);
        if ($calendar === '' || $recipient === '' || $access === '') return null;
        $action = ($plan['action'] ?? null) === 'remove_calendar_share' ? Translator::t('Remove the direct share') : Translator::t('Change the direct share');
        $lines = [Translator::t('%s for **%s**.', [$action, $recipient]), Translator::t('- Calendar: %s', [$calendar]), Translator::t('- Access: %s', [$access])];
        if (is_string($plan['existingAccess'] ?? null)) $lines[] = Translator::t('- Current access: %s', [$this->inline($plan['existingAccess'])]);
        return implode("\n", $lines);
    }

    public function restore(array $plan): ?string
    {
        $calendar = $this->inline($plan['calendar'] ?? null);
        $summary = $this->inline($plan['summary'] ?? null);
        $start = $this->inline($plan['start'] ?? null);
        $end = $this->inline($plan['end'] ?? null);
        if ($calendar === '' || $summary === '' || $start === '' || $end === '') return null;
        return Translator::t('Restore **%s** to the calendar trash location.', [$summary]) . "\n"
            . Translator::t('- Calendar: %s', [$calendar]) . "\n"
            . Translator::t('- When: %s to %s', [$start, $end]) . "\n"
            . Translator::t('- Invitations: no invitation is sent by native trash restore.');
    }

    private function inline(mixed $value): string
    {
        return is_scalar($value) ? PlanText::inline((string)$value) : '';
    }
}
