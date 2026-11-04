<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use DateTimeZone;
use OCA\Mcp\Modules\Mail\Presentation\Link\MailLinks;
use OCA\Mcp\Modules\Mail\Presentation\MailMessageRows;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class MailMessageRowsTest extends TestCase {
	public function testSearchSummaryContainsNoPreviewOrAttachmentContent(): void {
		$addresses = new class {
			public function iterate(): array { return []; }
		};
		$message = new class($addresses) {
			public function __construct(private object $addresses) {}
			public function getId(): int { return 9; }
			public function getMailboxId(): int { return 4; }
			public function getSubject(): string { return 'Quarterly update'; }
			public function getFrom(): object { return $this->addresses; }
			public function getTo(): object { return $this->addresses; }
			public function getCc(): object { return $this->addresses; }
			public function getSentAt(): int { return 1_700_000_000; }
			public function getFlagSeen(): bool { return false; }
			public function getFlagFlagged(): bool { return false; }
			public function getFlagAnswered(): bool { return false; }
			public function getFlagAttachments(): bool { return true; }
			public function getThreadRootId(): ?string { return null; }
			public function getPreviewText(): never { throw new \LogicException('Search must not read a body excerpt'); }
		};
		$urls = $this->createMock(IURLGenerator::class);
		$rows = new MailMessageRows(new DateTimeZone('UTC'), new MailLinks($urls));

		$summary = $rows->summary($message, 12);

		self::assertSame('Quarterly update', $summary['subject']);
		self::assertTrue($summary['hasAttachments']);
		self::assertArrayNotHasKey('preview', $summary);
		self::assertArrayNotHasKey('body', $summary);
		self::assertArrayNotHasKey('attachmentContent', $summary);
	}
}
