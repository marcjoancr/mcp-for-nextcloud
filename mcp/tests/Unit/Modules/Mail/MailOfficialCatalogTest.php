<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Modules\Mail\Access\MailAccessGuard;
use OCA\Mcp\Modules\Mail\MailModule;
use OCA\Mcp\Modules\Mail\MailToolCatalog;
use OCA\Mcp\Tests\Support\Dav\ScopeStub;
use PHPUnit\Framework\TestCase;

final class MailOfficialCatalogTest extends TestCase {
	public function testOfficialCatalogPublishesOnlyNarrowReadsAndOwnedDraftTools(): void {
		$names = array_column(MailToolCatalog::officialDefinitions(), 'name');
		self::assertSame([
			'mail_list_accounts', 'mail_list_mailboxes', 'mail_search_messages', 'mail_read_message',
			'mail_read_thread', 'mail_read_attachment', 'mail_save_attachment', 'mail_read_drafts',
			'mail_read_owned_drafts', 'mail_write_owned_draft', 'mail_delete_owned_draft',
		], $names);
	}

	public function testOfficialModeRejectsLegacyNativeDraftWriteEvenWhenFactoryExists(): void {
		$factories = array_fill_keys(array_keys(MailToolCatalog::definitions()), static fn () => throw new \LogicException('A hidden tool factory must not resolve'));
		$module = new MailModule($factories, new MailAccessGuard(new ScopeStub()), officialMode: static fn (): bool => true);
		$this->expectException(\InvalidArgumentException::class);
		$module->call('mail_write_draft', ['account_id' => 1, 'subject' => 'legacy'], 'alice');
	}

	public function testOfficialPublicationUsesTheNarrowListAndPatchedPublicationRemainsUnchanged(): void {
		$factories = array_fill_keys(array_keys(MailToolCatalog::definitions()), static fn () => throw new \LogicException('Metadata only'));
		$access = new MailAccessGuard(new ScopeStub());
		$official = new MailModule($factories, $access, officialMode: static fn (): bool => true);
		self::assertSame(array_column(MailToolCatalog::officialDefinitions(), 'name'), array_column($official->definitions(), 'name'));
		$patched = new MailModule($factories, $access);
		self::assertSame(array_column(MailToolCatalog::legacyDefinitions(), 'name'), array_column($patched->definitions(), 'name'));
		self::assertNotContains('mail_write_owned_draft', array_column($patched->definitions(), 'name'));
	}

	public function testOfficialSendIsPublishedOnlyWhenQualifiedAndEnabled(): void {
		$factories = array_fill_keys([...array_keys(MailToolCatalog::definitions()), 'mail_send_owned_draft'], static fn () => throw new \LogicException('Metadata only'));
		$access = new MailAccessGuard(new ScopeStub());
		$module = new MailModule($factories, $access, officialMode: static fn (): bool => true, officialSend: static fn (): bool => true);
		self::assertContains('mail_send_owned_draft', array_column($module->definitions(), 'name'));
		$closed = new MailModule($factories, $access, officialMode: static fn (): bool => true);
		self::assertNotContains('mail_send_owned_draft', array_column($closed->definitions(), 'name'));
		self::assertNotContains('mail_send_owned_draft', array_keys(MailToolCatalog::legacyDefinitions()));
	}

	public function testOfficialModeRejectsLegacyNativeDraftPreview(): void {
		$factories = array_fill_keys(array_keys(MailToolCatalog::definitions()), static fn () => throw new \LogicException('A hidden tool factory must not resolve'));
		$module = new MailModule($factories, new MailAccessGuard(new ScopeStub()), officialMode: static fn (): bool => true);
		$this->expectException(\InvalidArgumentException::class);
		$module->preview('mail_write_draft', ['account_id' => 1, 'subject' => 'legacy'], 'alice');
	}
}
