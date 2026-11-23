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

final class VcfReconcilePlanRenderer {
    private const LABELS = [
        'name' => 'Name', 'structuredName' => 'Structured name', 'organization' => 'Organization',
        'title' => 'Job title', 'note' => 'Note', 'url' => 'Website', 'emails' => 'E-mails',
        'phones' => 'Phones', 'addresses' => 'Addresses', 'categories' => 'Categories', 'birthday' => 'Birthday',
    ];

    public static function render(array $plan): string {
        $book = (string)($plan['addressbook'] ?? '');
        $source = PlanText::inline((string)($plan['source'] ?? ''), 1024);
        $lines = [Translator::t('Reconcile matching contacts from %s into %s.', [$source, PlanText::em($book)])];
        foreach ((array)($plan['contacts'] ?? []) as $entry) {
            if (!is_array($entry)) { continue; }
            $name = PlanText::inline((string)($entry['name'] ?? ''));
            $uid = PlanText::inline((string)($entry['uid'] ?? ''), 255);
            $status = PlanText::inline((string)($entry['status'] ?? ''));
            $uri = PlanText::inline((string)($entry['uri'] ?? ''), 255);
            $lines[] = '- ' . Translator::t('Entry %1$s (%2$s, UID %3$s): %4$s %5$s', [
                PlanText::inline((string)($entry['index'] ?? '')),
                $name,
                $uid,
                $status,
                $uri,
            ]);
            foreach ((array)($entry['changes'] ?? []) as $field => $change) {
                if (!is_array($change)) { continue; }
                $label = Translator::t(self::LABELS[$field] ?? 'Contact field');
                $before = self::value($change['before'] ?? null);
                $after = self::value($change['after'] ?? null);
                if (array_key_exists('beforeParameters', $change) || array_key_exists('afterParameters', $change)) {
                    $before .= ' (' . Translator::t('Parameters: %s', [self::value($change['beforeParameters'] ?? [])]) . ')';
                    $after .= ' (' . Translator::t('Parameters: %s', [self::value($change['afterParameters'] ?? [])]) . ')';
                }
                $lines[] = '  - ' . Translator::t('%1$s: %2$s → %3$s', [PlanText::inline($label), $before, $after]);
            }
            $unchanged = array_values(array_filter(array_map(static fn (mixed $value): string => is_string($value) ? (self::LABELS[$value] ?? '') : '', (array)($entry['unchangedFields'] ?? []))));
            if ($unchanged !== []) {
                $lines[] = '  - ' . Translator::t('Unchanged fields: %s', [PlanText::inline(implode(', ', $unchanged))]);
            }
        }
        if (($plan['backup']['required'] ?? false) === true) {
            $lines[] = Translator::t('A full vCard backup is saved and verified before every update; if backup verification fails, that contact is not updated.');
        }
        $lines[] = Translator::t('Only source fields listed above are replaced. Local fields absent from the file and unknown vCard properties are preserved.');
        foreach ((array)($plan['shared'] ?? []) as $notice) {
            if (is_array($notice) && is_string($notice['message'] ?? null)) {
                $lines[] = PlanText::inline($notice['message']);
            }
        }
        return implode("\n", $lines);
    }

    private static function value(mixed $value): string {
        if (is_array($value)) {
            $parts = [];
            array_walk_recursive($value, static function (mixed $item) use (&$parts): void {
                if (is_scalar($item)) { $parts[] = (string)$item; }
            });
            return PlanText::inline($parts === [] ? Translator::t('empty') : implode(', ', $parts), 500);
        }
        return PlanText::inline(is_scalar($value) ? (string)$value : Translator::t('empty'), 500);
    }
}
