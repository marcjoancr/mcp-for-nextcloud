<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Contacts

| Module | Read | Write |
| --- | --- | --- |
| **Contacts** | list personal/shared address books, search and read contacts, including the read-only accounts catalog of the instance (colleagues, with `accountId` for invitations) under the admin user-enumeration settings | create and edit while preserving unknown vCard fields; permanently delete only after a verified `.vcf` backup under `Contacts` in the configured backup folder (default `/MCP backups/Contacts`; mandatory even if Files copies are suspended) |

[Contacts and Tasks behavior](contacts-tasks.md).

See the live `mcp_guide` for the authoritative schema and available operations.

## Find and maintain contact cards

- Use `contacts_list_addressbooks` to find your personal and shared books and, when the administrator exposes it, the read-only accounts directory.
- Search with `contacts_search_contacts`, then use `contacts_read_contact` for the selected card. If you need a Nextcloud account for an invitation, use the returned `accountId` when available.
- Use `contacts_create_contact` to add a card or `contacts_edit_contact` to change it in a writable book. For deletion, `contacts_delete_contact` verifies a vCard backup before it removes the card and returns the backup path.
- Review the write preview and confirm the same change. Include the ETag from the preview when editing or deleting an existing card. For a shared book, also acknowledge the change to the shared book with `confirm_shared: true`.

## Groups, fields and vCard exchange

`contacts_list_groups` lists categories in a visible address book. Pass `group` to `contacts_search_contacts` for an exact category match. Create and edit support structured names and addresses, categories, a birthday and a preferred email. Contact fields follow the stored vCard version.

`contacts_export_vcf` returns the selected cards as a vCard document. Save the payload as a `.vcf` file. Use `contacts_import_vcf` with a file you can read in Files or with the backup path returned after deleting a contact. The plan lists which cards will be created or skipped. Repeating an import with a fresh plan skips UIDs already present in the book.

`contacts_find_duplicates` finds candidates by matching normalized emails or phone numbers. A shared name and organization is a weaker hint for review. Review the references and ETags before deciding which cards to consolidate.

Use `contacts_merge_contacts` with up to five disjoint groups. Each group contains two to ten cards in one writable address book. Pick the card to keep and review the proposed values for each conflict. After approval, repeat with the ETags and `plan_state` from the preview. The tool verifies the original backups and the consolidated card before deleting sources. It reports any groups that still need review and their backup paths.

## Address books and contact transfers

Use `contacts_create_addressbook` for a new personal book and `contacts_list_addressbook_shares` to inspect sharing on a book you own. `contacts_share_addressbook` manages direct sharing with local users. Set read or write access or remove a current share, then review the change in the preview.

`contacts_copy_contact` creates a distinct contact in a writable destination and leaves the source in place. `contacts_move_contact` keeps the contact UID, verifies the destination and saves a backup before deleting the source. Confirm the transfer with the plan’s `plan_state`. Include the source ETag for a move and check the reported state of both copies if a step fails. Shared books require separate approval for each affected collection.

[Operational guides](../../guides/README.md).

## Batch maintenance

`contacts_update_categories` adds, removes or renames one category on selected cards in one writable address book. Supply the cards' ETags. The preview shows the categories before and after, and the result reports each card's outcome.

`contacts_reconcile_vcf` updates cards with an exact, unique UID match in a VCF visible in Files. Review the current, proposed and preserved values. Each update requires a verified full backup. Cards without a unique match remain for review. Confirm each operation with its `plan_state` and acknowledge writes on shared address books separately.

English · [Español](../../../es/reference/tools/contacts.md) · [Català](../../../ca/reference/tools/contacts.md) · [Português (Brasil)](../../../pt-BR/reference/tools/contacts.md) · [Português (Portugal)](../../../pt-PT/reference/tools/contacts.md)
