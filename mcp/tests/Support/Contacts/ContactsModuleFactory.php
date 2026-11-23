<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Support\Contacts;
final class ContactsModuleFactory {
    public static function create($access, $store, $dav, $cards, $guard, $backup, $system, ?\OCP\IUserManager $users = null, ?\OCP\Collaboration\Collaborators\ISearch $shareSearch = null, ?\OCA\Mcp\Mcp\Confirmation\PlanState $states = null): \OCA\Mcp\Modules\Contacts\ContactsModule {
        $repository = new \OCA\Mcp\Modules\Contacts\Cards\ContactRepository($store);
        $plans = new \OCA\Mcp\Modules\Contacts\Execution\ContactPlan($guard, $cards);
        $writer = new \OCA\Mcp\Modules\Contacts\Cards\ContactWriter($dav, $repository, $cards);
        $transfer = new \OCA\Mcp\Modules\Contacts\Relocation\ContactTransfer($access, $store, $repository, $cards, $plans, $writer, $backup, $dav, $states ?? throw new \LogicException('Plan state unavailable in this test factory'));
        $shareManager = $users === null || $shareSearch === null ? null : new \OCA\Mcp\Modules\Contacts\AddressBooks\AddressBookShareManager($access, $store, $users, $dav, $shareSearch);
        return new \OCA\Mcp\Modules\Contacts\ContactsModule([
            'contacts_list_addressbooks' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\ListAddressBooks(new \OCA\Mcp\Modules\Contacts\AddressBooks\AddressBookReader($access, $system)),
            'contacts_list_groups' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\ListContactGroups(new \OCA\Mcp\Modules\Contacts\Groups\ContactGroupReader($access, $store, $cards)),
            'contacts_update_categories' => static fn () => throw new \LogicException('Bulk category editor is exercised directly in its service tests.'),
            'contacts_create_addressbook' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\CreateAddressBook(new \OCA\Mcp\Modules\Contacts\AddressBooks\AddressBookCreator($access, $dav)),
            'contacts_copy_contact' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\CopyContact($transfer),
            'contacts_move_contact' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\MoveContact($transfer),
            'contacts_list_addressbook_shares' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\ListAddressBookShares($shareManager ?? throw new \LogicException('Share manager unavailable in this test factory')),
            'contacts_share_addressbook' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\ShareAddressBook($shareManager ?? throw new \LogicException('Share manager unavailable in this test factory')),
            // Exchange and duplicate scenarios are exercised against their services directly; this
            // minimal module factory only needs stable catalog entries for unrelated module tests.
            'contacts_find_duplicates' => static fn () => throw new \LogicException('Duplicate finder unavailable in this test factory'),
            'contacts_export_vcf' => static fn () => throw new \LogicException('vCard exchange unavailable in this test factory'),
            'contacts_import_vcf' => static fn () => throw new \LogicException('vCard exchange unavailable in this test factory'),
            'contacts_reconcile_vcf' => static fn () => throw new \LogicException('vCard reconciliation is exercised directly in its service tests.'),
            'contacts_merge_contacts' => static fn () => throw new \LogicException('Contact merger unavailable in this test factory'),
            'contacts_search_contacts' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\SearchContacts(new \OCA\Mcp\Modules\Contacts\Search\ContactSearch($access, $store, $cards, $system)),
            'contacts_read_contact' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\ReadContact(new \OCA\Mcp\Modules\Contacts\Reading\ContactReader($access, $system, $repository, $cards)),
            'contacts_create_contact' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\CreateContact(new \OCA\Mcp\Modules\Contacts\Creation\ContactCreator($access, $repository, $cards, $plans, $writer)),
            'contacts_edit_contact' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\EditContact(new \OCA\Mcp\Modules\Contacts\Editing\ContactEditor($access, $repository, $cards, $plans, $writer)),
            'contacts_delete_contact' => static fn () => new \OCA\Mcp\Modules\Contacts\Tools\DeleteContact(new \OCA\Mcp\Modules\Contacts\Deletion\ContactDeleter($access, $repository, $cards, $plans, $backup, $dav)),
        ]);
    }
}
