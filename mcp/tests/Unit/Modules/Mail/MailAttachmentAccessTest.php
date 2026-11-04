<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Attachments\MailAttachmentAccess;
use OCA\Mcp\Modules\Mail\Integration\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Integration\MailBodyPreflight;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use PHPUnit\Framework\TestCase;

final class MailAttachmentAccessTest extends TestCase {
	public function testInvalidMimePartIdNeverReachesMailServices(): void {
		$locator = $this->createMock(MailMessageLocator::class);
		$locator->expects(self::never())->method('locate');
		$services = $this->createMock(MailServices::class);
		$services->expects(self::never())->method('imapMessageMapper');
		$access = new MailAttachmentAccess($locator, $services);

		try {
			$access->read('alice', 42, "2.1\r\n1");
			self::fail('Malformed MIME part id was accepted.');
		} catch (ToolFailure $e) {
			self::assertSame(MailMessages::attachmentNotFound(), $e->getMessage());
		}
	}

	public function testMessageWithoutVerifiableCacheGenerationNeverOpensAnImapClient(): void {
		$mailbox = new class {
			public function getSyncNewToken(): ?string { return null; }
			public function getSyncChangedToken(): ?string { return null; }
			public function getSyncVanishedToken(): ?string { return null; }
		};
		$locator = $this->createMock(MailMessageLocator::class);
		$locator->method('locate')->willReturn(['message' => new \stdClass(), 'mailbox' => $mailbox, 'account' => new \stdClass()]);
		$services = $this->createMock(MailServices::class);
		$services->expects(self::never())->method('clientFactory');
		$access = new MailAttachmentAccess($locator, $services);

		try {
			$access->list('alice', 42);
			self::fail('An unverifiable cached UID generation was read.');
		} catch (ToolFailure $e) {
			self::assertSame(MailMessages::messageChanged(), $e->getMessage());
		}
	}

	public function testMessageWithMoreThanOneHundredAttachmentsIsRefusedInsteadOfSilentlyTruncated(): void {
		$token = base64_encode('U11,V10');
		$mailbox = new class($token) {
			public function __construct(private string $token) {}
			public function getSyncNewToken(): string { return $this->token; }
			public function getSyncChangedToken(): string { return $this->token; }
			public function getSyncVanishedToken(): string { return $this->token; }
			public function getName(): string { return 'INBOX'; }
		};
		$message = new class { public function getUid(): int { return 7; } public function getMessageId(): string { return 'm@example.test'; } };
		$account = new \stdClass();
		$locator = $this->createMock(MailMessageLocator::class);
		$locator->method('locate')->willReturn(['message' => $message, 'mailbox' => $mailbox, 'account' => $account]);
		$client = new class { public function logout(): void {} };
		$factory = new class($client) { public function __construct(private object $client) {} public function getClient(object $account): object { return $this->client; } };
		$attachments = array_fill(0, MailAttachmentAccess::MAX_ATTACHMENTS + 1, new class {
			public function getId(): string { return '1'; }
			public function getName(): string { return 'file'; }
			public function getType(): string { return 'text/plain'; }
			public function getContent(): string { return 'x'; }
		});
		$mapper = new class($attachments) { public function __construct(private array $attachments) {} public function getAttachments(object $client, string $name, int $uid, string $userId): array { return $this->attachments; } };
		$services = $this->createMock(MailServices::class);
		$services->method('clientFactory')->willReturn($factory);
		$services->method('imapMessageMapper')->willReturn($mapper);
		$preflight = $this->createMock(MailBodyPreflight::class);
		$preflight->method('uidValidity')->willReturn('10');
		$preflight->method('check')->willReturn(1);
		$access = new MailAttachmentAccess($locator, $services, $preflight);

		$this->expectException(ToolFailure::class);
		$this->expectExceptionMessage(MailMessages::tooManyAttachments(MailAttachmentAccess::MAX_ATTACHMENTS));
		$access->list('alice', 42);
	}
}
