<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Presentation;

use OCA\Mcp\L10n\Translator;

final class ContactTransferPlanRenderer
{
    public function render(string $tool, array $plan, ContactsPlanText $text): ?string
    {
        $source = $text->name($plan['sourceAddressbook'] ?? null);
        $target = $text->name($plan['addressbook'] ?? null);
        $before = $text->card($plan['before'] ?? null);
        $after = $text->card($plan['after'] ?? null);
        if ($source === null || $target === null || $before === null || $after === null) {
            return null;
        }
        $name = $text->text($before['name'] ?? '') ?: $text->text($before['uid'] ?? '');
        if ($name === '') {
            return null;
        }
        if ($tool === 'contacts_copy_contact') {
            $body = Translator::t('Copy the contact %s from %s to %s. The source stays unchanged; a new UID and URI are assigned when the copy is created.', [
                $text->strong($name), $text->emphasis($source), $text->emphasis($target),
            ]);
        } elseif ($tool === 'contacts_move_contact') {
            $body = Translator::t('Move the contact %s from %s to %s. The destination is created and verified first; a source vCard backup is saved before the source is removed.', [
                $text->strong($name), $text->emphasis($source), $text->emphasis($target),
            ]);
        } else {
            return null;
        }
        return $text->shared($body, $plan, $target);
    }
}
