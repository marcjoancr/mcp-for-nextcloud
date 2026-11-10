<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Modules\Mail\MailToolCatalog;
use PHPUnit\Framework\TestCase;

final class MailBoundedReadsContractTest extends TestCase {
	public function testOutboxAndMetadataReadsArePublishedWithSeparateSafeContracts(): void {
		$definitions = MailToolCatalog::definitions();

		self::assertArrayHasKey('mail_read_outbox', $definitions);
		self::assertSame('read_outbox', $definitions['mail_read_outbox']['operation']);
		self::assertTrue(WriteGate::isWrite($definitions['mail_read_outbox']) === false);
		self::assertContains('read_outbox', GrantPolicy::CATALOG['mail']);
		self::assertArrayNotHasKey('mail_send', $definitions);

		self::assertArrayHasKey('mail_read_message_metadata', $definitions);
		self::assertSame('read_message', $definitions['mail_read_message_metadata']['operation']);
		self::assertFalse(WriteGate::isWrite($definitions['mail_read_message_metadata']));
		self::assertArrayNotHasKey('body', $definitions['mail_read_message_metadata']['inputSchema']['properties']);
		self::assertArrayNotHasKey('preview', $definitions['mail_read_message_metadata']['inputSchema']['properties']);
		self::assertArrayNotHasKey('recipients', $definitions['mail_read_message_metadata']['inputSchema']['properties']);
	}
}
