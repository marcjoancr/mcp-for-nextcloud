<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Modules\Mail\Tools\{DeleteDraft, ListAccounts, ListMailboxes, ReadAttachment, ReadDrafts, ReadMessage, ReadThread, ReadMessageMetadata, ReadOutbox, SaveAttachment, SearchMessages, WriteDraft};

final class MailToolCatalog {
    /** @return list<class-string<Tool>> */
    public static function classes(): array {
		return [ListAccounts::class, ListMailboxes::class, SearchMessages::class, ReadMessage::class, ReadThread::class,
			ReadAttachment::class, SaveAttachment::class, ReadDrafts::class, WriteDraft::class, DeleteDraft::class, Tools\AttachFiles::class, Tools\GetOperation::class, Tools\ListOperations::class, Tools\ReadAccountSettings::class, Tools\WriteAccountSettings::class, Tools\ReadSignature::class, Tools\WriteSignature::class, Tools\ReadConnection::class, Tools\ConfigureConnection::class, Tools\TestConnection::class, Tools\AddAccount::class, Tools\CompleteAccountSetup::class, Tools\DeleteAccount::class, Tools\SetMessageFlag::class, Tools\MoveMessage::class, Tools\ArchiveMessage::class, Tools\TrashMessage::class, Tools\RestoreMessage::class, Tools\CreateMailbox::class, Tools\RenameMailbox::class, Tools\SetMailboxSubscription::class, Tools\ConfigureMailboxes::class, Tools\ListAliases::class, Tools\ListTags::class, Tools\CreateTag::class, Tools\SetMessageTag::class, Tools\RefreshMailbox::class, Tools\ReconcileOperation::class, Tools\AddAlias::class, Tools\UpdateAlias::class, Tools\DeleteAlias::class, ReadOutbox::class, ReadMessageMetadata::class, Tools\SendDraft::class, Tools\ScheduleDraft::class, Tools\CancelDelivery::class, Tools\ReadDelivery::class, Tools\RepairSentCopy::class, Tools\ExportMessage::class];
    }

    /**
     * @param list<class-string<Tool>>|null $classes explicit entries, defaulting to this feature's catalog
     * @return array<string, array<string, mixed>> original metadata shape in publication order
     */
    public static function definitions(?array $classes = null): array {
        $result = [];
        foreach ($classes ?? self::classes() as $class) {
            if (!is_a($class, Tool::class, true)) {
                throw new \LogicException('Catalog entries must implement Tool');
            }
            $definition = $class::definition();
			if (!in_array($definition->operation, ['read', 'search', 'read_message', 'read_outbox', 'read_attachment', 'save_attachment', 'read_drafts', 'write_drafts', 'delete_drafts', 'attach_files', 'read_operations', 'read_settings', 'write_settings', 'read_signatures', 'write_signatures', 'read_connection', 'configure_connection', 'create_accounts', 'complete_setup', 'test_connection', 'delete_accounts', 'set_flags', 'move_messages', 'archive_messages', 'trash_messages', 'restore_messages', 'create_mailboxes', 'rename_mailboxes', 'subscribe_mailboxes', 'configure_mailboxes', 'read_identities', 'read_tags', 'create_tags', 'tag_messages', 'refresh', 'reconcile_operations', 'create_identities', 'edit_identities', 'delete_identities', 'send', 'schedule', 'cancel_delivery', 'read_delivery', 'repair_sent', 'export_message'], true)) {
				throw new \LogicException('Mail tools must use a declared Mail operation');
            }
            if (isset($result[$definition->name])) {
                throw new \LogicException('Duplicate tool: ' . $definition->name);
            }
			$result[$definition->name] = $definition->toArray();
        }
        return $result;
    }
}
