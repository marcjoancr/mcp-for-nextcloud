<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Presentation;

use OCA\Mcp\L10n\Translator;

final class ContactsPlanRenderer
{
    public function render(string $tool, array $plan): ?string
    {
        if ($tool === 'contacts_import_vcf') {
            return VcfImportPlanRenderer::render($plan);
        }
        if ($tool === 'contacts_reconcile_vcf') {
            return VcfReconcilePlanRenderer::render($plan);
        }
        if ($tool === 'contacts_update_categories') {
            return (new ContactCategoryPlanRenderer())->render($plan);
        }
        if ($tool === 'contacts_merge_contacts') {
            $rendered = [];
            foreach ((array) ($plan['groups'] ?? []) as $index => $group) {
                if (!is_array($group)) {
                    continue;
                }
                $body = (new ContactMergePlanRenderer())->render($group);
                if ($body !== null) {
                    $rendered[] = Translator::t('Group %s', [\OCA\Mcp\Mcp\Confirmation\PlanText::inline((string) ($index + 1))])
                        . "\n" . $body;
                }
            }
            return $rendered === [] ? null : implode("\n\n", $rendered);
        }
        $text = new ContactsPlanText();
        if ($tool === 'contacts_share_addressbook') {
            $book = $text->name($plan['addressbook'] ?? null);
            $recipient = $text->card($plan['recipient'] ?? null);
            if ($book === null || $recipient === null || $text->text($recipient['displayName'] ?? '') === '') {
                return null;
            }
            $access = $text->text($plan['access'] ?? '');
            if ($access === 'remove') {
                return \OCA\Mcp\L10n\Translator::t('Remove the share to %s on address book %s.', [
                    $text->strong($text->text($recipient['displayName'])),
                    $text->emphasis($book),
                ]);
            }
            if (!in_array($access, ['read-only', 'read-write'], true)) {
                return null;
            }
            $body = ($plan['existingAccess'] ?? null) === null
                ? \OCA\Mcp\L10n\Translator::t('Share the address book %s with %s and grant %s access.', [
                    $text->emphasis($book),
                    $text->strong($text->text($recipient['displayName'])),
                    $text->strong($access),
                ])
                : \OCA\Mcp\L10n\Translator::t('Change access on the address book %s for %s to %s.', [
                    $text->emphasis($book),
                    $text->strong($text->text($recipient['displayName'])),
                    $text->strong($access),
                ]);
            return $text->shared($body, $plan, $book);
        }
        if ($tool === 'contacts_create_addressbook') {
            $name = $text->text($plan['name'] ?? '');
            return $name === '' ? null : \OCA\Mcp\L10n\Translator::t('Create the personal address book %s.', [$text->strong($name)]);
        }
        if (in_array($tool, ['contacts_copy_contact', 'contacts_move_contact'], true)) {
            return (new ContactTransferPlanRenderer())->render($tool, $plan, $text);
        }
        $book = $text->name($plan['addressbook'] ?? null);
        if ($book === null) {
            return null;
        }
        $body = match ($tool) {
            'contacts_create_contact' => (new ContactSaveRenderer($text))->create($book, $plan),
            'contacts_edit_contact' => (new ContactSaveRenderer($text))->edit($book, $plan),
            'contacts_delete_contact' => (new ContactDeletionRenderer($text))->delete($book, $plan),
            default => null,
        };
        if ($body === null) {
            return null;
        }
        return $text->shared($body, $plan, $book);
    }
}
