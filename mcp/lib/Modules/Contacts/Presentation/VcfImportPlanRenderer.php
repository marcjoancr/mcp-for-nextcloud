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

final class VcfImportPlanRenderer {
    public static function render(array $plan): string {
        $book = PlanText::inline((string)$plan['addressbook']);
        $source = PlanText::inline((string)$plan['source']);
        $lines = [Translator::t('Import contacts from %1$s into %2$s.', [$source, $book])];
        foreach ($plan['contacts'] as $contact) {
            $lines[] = '- ' . Translator::t('Entry %1$s (%2$s) — %3$s', [
                PlanText::inline((string)$contact['index']),
                PlanText::inline((string)($contact['name'] ?? '')),
                PlanText::inline((string)$contact['status']),
            ]);
        }
        foreach ($plan['shared'] as $shared) {
            $lines[] = '- ' . PlanText::inline((string)($shared['message'] ?? ''));
        }
        return implode("\n", $lines);
    }
}
