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

final class ContactCategoryPlanRenderer {
    public function render(array $plan): ?string {
        $text = new ContactsPlanText();
        $book = $text->name($plan['addressbook'] ?? null);
        $operation = $plan['operation'] ?? null;
        if ($book === null || !is_array($operation) || !is_array($plan['contacts'] ?? null)) { return null; }
        $action = $operation['action'] ?? '';
        $title = match ($action) {
            'add' => Translator::t('Add the category %s to selected contacts in %s.', [PlanText::strong((string)($operation['category'] ?? '')), PlanText::em($book)]),
            'remove' => Translator::t('Remove the category %s from selected contacts in %s.', [PlanText::strong((string)($operation['category'] ?? '')), PlanText::em($book)]),
            'rename' => Translator::t('Rename the category %s to %s on selected contacts in %s.', [PlanText::strong((string)($operation['category'] ?? '')), PlanText::strong((string)($operation['replacement'] ?? '')), PlanText::em($book)]),
            default => null,
        };
        if ($title === null) { return null; }
        $lines = [$title];
        foreach ($plan['contacts'] as $entry) {
            if (!is_array($entry)) { continue; }
            $name = PlanText::inline((string)($entry['name'] ?? ''));
            $uri = PlanText::inline((string)($entry['uri'] ?? ''), 255);
            $etag = PlanText::inline((string)($entry['etag'] ?? ''), 200);
            $status = PlanText::inline((string)($entry['status'] ?? ''));
            $line = '- ' . Translator::t('%s (%s; ETag %s): %s', [$name, $uri, $etag, $status]);
            $before = is_array($entry['before']['categories'] ?? null) ? $entry['before']['categories'] : [];
            $after = is_array($entry['after']['categories'] ?? null) ? $entry['after']['categories'] : [];
            $line .= ' — ' . Translator::t('Categories: %s → %s', [$this->list($before), $this->list($after)]);
            $lines[] = $line;
        }
        $lines[] = Translator::t('Only the selected category is changed; other categories and vCard properties are preserved.');
        return $text->shared(implode("\n", $lines), $plan, $book);
    }

    private function list(array $values): string {
        return PlanText::inline($values === [] ? Translator::t('none') : implode(', ', array_map('strval', $values)), 500);
    }
}
