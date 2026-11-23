<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

final class ContactMergePlanRenderer
{
    public function render(array $plan): ?string
    {
        $book = (new ContactsPlanText())->name($plan['addressbook'] ?? null);
        if ($book === null || !is_array($plan['contacts'] ?? null) || !is_array($plan['conflicts'] ?? null)) {
            return null;
        }
        $keepUri = (string) ($plan['keepUri'] ?? '');
        $keep = null;
        foreach ($plan['contacts'] as $contact) {
            if (is_array($contact) && ($contact['uri'] ?? null) === $keepUri) {
                $keep = $contact;
                break;
            }
        }
        if (!is_array($keep)) {
            return null;
        }
        $lines = [Translator::t('Merge %1$s contacts in %2$s and keep %3$s.', [
            PlanText::inline((string) count($plan['contacts'])),
            PlanText::em($book),
            PlanText::inline((string) ($keep['name'] ?? $keepUri)),
        ])];
        foreach ($plan['contacts'] as $contact) {
            if (!is_array($contact) || !is_string($contact['uri'] ?? null)) {
                continue;
            }
            $lines[] = '- ' . PlanText::inline((string) ($contact['name'] ?? ''))
                . ' — ' . PlanText::inline($contact['uri']);
        }
        foreach ($plan['conflicts'] as $conflict) {
            if (!is_array($conflict) || !is_string($conflict['field'] ?? null)) {
                continue;
            }
            $lines[] = '- ' . Translator::t('Choose a value for %s:', [PlanText::inline($conflict['field'])]);
            foreach ((array) ($conflict['candidates'] ?? []) as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $value = (string) ($candidate['value'] ?? '');
                $value = $value === '' ? Translator::t('No value (clear)') : $value;
                $lines[] = '  - ' . PlanText::inline((string) ($candidate['uri'] ?? ''))
                    . ': ' . PlanText::inline($value);
            }
            if (($conflict['choice'] ?? null) !== null) {
                $lines[] = Translator::t('Selected: %s', [PlanText::inline((string) $conflict['choice'])]);
            }
        }
        if (($plan['ready'] ?? false) !== true) {
            $lines[] = Translator::t('Choose a source or clear value for every conflict before confirming.');
        }
        $lines[] = Translator::t('Verified vCard backups are saved for every contact before writing or deleting.');
        $lines[] = Translator::t('The merge keeps distinct additional properties and parameters, including PHOTO, KEY and X-* fields.');
        foreach ((array) ($plan['shared'] ?? []) as $notice) {
            if (is_array($notice) && is_string($notice['ownerDisplayName'] ?? $notice['owner'] ?? null)) {
                $lines[] = Translator::t('This address book is shared by %s; changes affect other people.', [
                    PlanText::inline((string) ($notice['ownerDisplayName'] ?? $notice['owner'])),
                ]);
            }
        }
        return implode("\n", $lines);
    }
}
