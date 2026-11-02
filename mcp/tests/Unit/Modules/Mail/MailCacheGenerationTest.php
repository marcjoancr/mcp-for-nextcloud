<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Modules\Mail\Integration\MailCacheGeneration;
use PHPUnit\Framework\TestCase;

final class MailCacheGenerationTest extends TestCase {
	public function testItAcceptsOnlyThreeMatchingUidValidityTokens(): void {
		$token = base64_encode('U99,V123,Habc');
		$mailbox = new class($token) {
			public function __construct(private string $token) {}
			public function getSyncNewToken(): string { return $this->token; }
			public function getSyncChangedToken(): string { return $this->token; }
			public function getSyncVanishedToken(): string { return $this->token; }
		};

		self::assertSame('123', MailCacheGeneration::of($mailbox));
	}

	public function testItRefusesMissingOrMismatchedUidValidityTokens(): void {
		$mailbox = new class {
			public function getSyncNewToken(): string { return base64_encode('U99,V123'); }
			public function getSyncChangedToken(): string { return base64_encode('U99,V124'); }
			public function getSyncVanishedToken(): null { return null; }
		};

		self::assertNull(MailCacheGeneration::of($mailbox));
	}
}
