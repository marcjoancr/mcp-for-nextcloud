<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Modules\Mail\Tools\{DeleteDraft, ListAccounts, ListMailboxes, ReadAttachment, ReadDrafts, ReadMessage, ReadThread, SaveAttachment, SearchMessages, WriteDraft};

final class MailToolCatalog {
    /** @return list<class-string<Tool>> */
    public static function classes(): array {
		return [ListAccounts::class, ListMailboxes::class, SearchMessages::class, ReadMessage::class, ReadThread::class,
			ReadAttachment::class, SaveAttachment::class, ReadDrafts::class, WriteDraft::class, DeleteDraft::class, Tools\AttachFiles::class, Tools\GetOperation::class, Tools\ListOperations::class];
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
			if (!in_array($definition->operation, ['read', 'search', 'read_message', 'read_attachment', 'save_attachment', 'read_drafts', 'write_drafts', 'delete_drafts', 'attach_files', 'read_operations'], true)) {
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
