<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Mail;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Modules\Mail\MailModule;
use OCA\Mcp\Modules\Mail\MailToolCatalog;

/**
 * Representative, user-readable plan payloads for every Mail write. Factories are intentionally lazy failures:
 * this fixture tests only module metadata and its production renderer, never Mail or Files side effects.
 */
final class MailWritePlanFixtures {
	/** @return array{MailModule,array<string,array<string,mixed>>} */
	public static function create(): array {
		$factories = [];
		foreach (array_keys(MailToolCatalog::definitions()) as $name) {
			$factories[$name] = static function () use ($name): Tool {
				throw new \LogicException('Plan readability tests must not resolve ' . $name);
			};
		}

		return [new MailModule($factories), [
			'mail_save_attachment' => [
				'attachment' => ['name' => 'Quarterly report.pdf', 'message_id' => 42, 'mimeType' => 'application/pdf', 'size' => 2048],
				'destination' => ['path' => '/Shared/Quarterly report.pdf', 'folder' => '/Shared',
					'access' => ['scope' => 'shared', 'ownerDisplayName' => 'Avery'], 'shared' => []],
			],
			'mail_write_draft' => [
				'action' => 'create', 'account' => ['email' => 'alice@example.test'],
				'after' => ['subject' => 'Project update', 'body_plain' => 'The review is ready.', 'body_html' => '', 'is_html' => false,
					'to' => [['label' => 'Morgan', 'email' => 'morgan@example.test']], 'cc' => [], 'bcc' => [], 'attachments' => []],
			],
            'mail_attach_files' => ['action' => 'update', 'account' => ['email' => 'alice@example.test'], 'draft_id' => 73,
                'after' => ['subject' => 'Draft', 'body_plain' => 'Body', 'attachments' => [['name' => 'budget.pdf', 'mimeType' => 'application/pdf']]]],
			'mail_delete_draft' => [
				'action' => 'delete', 'draft' => ['id' => 73, 'subject' => 'Old proposal',
					'body_plain' => 'This local draft is no longer needed.', 'body_html' => '', 'is_html' => false,
					'recipients' => ['to' => [['label' => 'Casey', 'email' => 'casey@example.test']], 'cc' => [], 'bcc' => []],
					'attachments' => []],
			],
		]];
	}
}
