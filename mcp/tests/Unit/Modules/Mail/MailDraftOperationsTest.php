<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Mail\Drafts\MailDraftOperations;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;

final class MailDraftOperationsTest extends TestCase {
	public function testSubjectOnlyUpdatePreservesOmittedBodyRecipientsAndHtmlMode(): void {
		$draft = new class {
			public function getId(): int { return 77; }
			public function getAccountId(): int { return 4; }
			public function getUpdatedAt(): int { return 100; }
			public function getSubject(): string { return 'Before'; }
			public function getBodyPlain(): string { return 'plain body'; }
			public function getBodyHtml(): string { return '<p>html body</p>'; }
			public function isHtml(): bool { return true; }
			public function getAttachments(): array { return []; }
			public function getRecipients(): array {
				return [new class { public function getType(): int { return 1; } public function getLabel(): string { return 'Recipient'; } public function getEmail(): string { return 'recipient@example.test'; } }];
			}
		};
		$draftService = new class($draft) { public function __construct(private object $draft) {} public function getMessage(int $id, string $uid): object { return $this->draft; } };
		$services = $this->createMock(MailServices::class);
		$services->method('draftsService')->willReturn($draftService);
		$accounts = new MailGateway((new FakeMailApp())->withAccount(4, 'alice', 'Alice', 'alice@example.test'));
		$operations = new MailDraftOperations($services, $accounts, new PlanState((new InMemoryConfig())->mock($this)));

		$plan = $operations->previewWrite(['account_id' => 4, 'draft_id' => 77, 'subject' => 'After'], 'alice');

		self::assertSame('plain body', $plan['after']['body_plain']);
		self::assertSame('<p>html body</p>', $plan['after']['body_html']);
		self::assertTrue($plan['after']['is_html']);
		self::assertSame([['label' => 'Recipient', 'email' => 'recipient@example.test']], $plan['after']['to']);
		self::assertSame([], $plan['after']['cc']);
		self::assertSame([], $plan['after']['bcc']);
	}

	public function testSubjectOnlyUpdatePreservesRawNativeRecipientValues(): void {
		$label = "Person\u{202E}name";
		$draft = new MailDraftTestDouble(77, 4, 100, 'Before', 'Body', '', false, [], [new class($label) {
			public function __construct(private string $label) {}
			public function getType(): int { return 1; }
			public function getLabel(): string { return $this->label; }
			public function getEmail(): string { return 'person@example.test'; }
		}]);
		$service = new class($draft) { public function __construct(private object $draft) {} public function getMessage(int $id, string $uid): object { return $this->draft; } };
		$plan = $this->operations($service)->previewWrite(['account_id' => 4, 'draft_id' => 77, 'subject' => 'After'], 'alice');

		self::assertSame($label, $plan['after']['to'][0]['label']);
	}

	public function testOversizedExistingBodyIsNotIncludedAsAnUnboundedUpdatePlan(): void {
		$draft = new MailDraftTestDouble(77, 4, 100, 'Before', str_repeat('x', MailDraftOperations::MAX_BODY_CHARS + 1), '', false, [], []);
		$service = new class($draft) { public function __construct(private object $draft) {} public function getMessage(int $id, string $uid): object { return $this->draft; } };
		$this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
		$this->expectExceptionMessage(\OCA\Mcp\Modules\Mail\Presentation\MailMessages::draftTooLarge(MailDraftOperations::MAX_BODY_CHARS));
		$this->operations($service)->previewWrite(['account_id' => 4, 'draft_id' => 77, 'subject' => 'After'], 'alice');
	}

	public function testDraftBelongingToAnotherOwnedAccountIsStillNotFound(): void {
		$draft = new MailDraftTestDouble(77, 8, 100, 'Subject', 'Body', '', false, [], []);
		$service = new class($draft) { public function __construct(private object $draft) {} public function getMessage(int $id, string $uid): object { return $this->draft; } };
		$operations = $this->operations($service);
		try {
			$operations->previewWrite(['account_id' => 4, 'draft_id' => 77, 'subject' => 'Updated'], 'alice');
			self::fail('A draft from another account was accepted.');
		} catch (\OCA\Mcp\Mcp\Tools\Results\ToolFailure $e) {
			self::assertSame(\OCA\Mcp\Modules\Mail\Presentation\MailMessages::draftNotFound(), $e->getMessage());
		}
	}

	public function testUpdateWithAttachmentsIsRefusedBeforeCallingNativeUpdate(): void {
		$draft = new MailDraftTestDouble(77, 4, 100, 'Subject', 'Body', '', false, [new \stdClass()], []);
		$service = new class($draft) {
			public function __construct(private object $draft) {}
			public function getMessage(int $id, string $uid): object { return $this->draft; }
			public function updateMessage(): never { throw new \LogicException('Native update must not be called.'); }
		};
		$operations = $this->operations($service);
		$this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
		$this->expectExceptionMessage(\OCA\Mcp\Modules\Mail\Presentation\MailMessages::draftAttachmentsCannotEdit());
		$operations->previewWrite(['account_id' => 4, 'draft_id' => 77, 'subject' => 'Updated'], 'alice');
	}

	public function testChangedExistingBodyMakesTheOldPlanUnusable(): void {
		$draft = new MailDraftTestDouble(77, 4, 100, 'Subject', 'Before', '', false, [], []);
		$service = new class($draft) {
			public function __construct(private object $draft) {}
			public function getMessage(int $id, string $uid): object { return $this->draft; }
			public function updateMessage(): never { throw new \LogicException('A stale plan must not update the draft.'); }
		};
		$operations = $this->operations($service);
		$args = ['account_id' => 4, 'draft_id' => 77, 'subject' => 'After'];
		$plan = $operations->previewWrite($args, 'alice');
		$draft->bodyPlain = 'Changed after preview';
		$this->expectException(\OCA\Mcp\Mcp\Confirmation\PlanChanged::class);
		$operations->write($args + ['plan_state' => $plan['plan_state']], 'alice');
	}

	public function testDraftListingReturnsAStableBoundedOffsetPage(): void {
		$rows = [
			new MailDraftTestDouble(1, 4, 300, 'Newest', '', '', false, [], []),
			new MailDraftTestDouble(99, 8, 250, 'Foreign account row', '', '', false, [], []),
			new MailDraftTestDouble(2, 4, 200, 'Middle', '', '', false, [], []),
			new MailDraftTestDouble(3, 4, 100, 'Oldest', '', '', false, [], []),
		];
		$mapper = new class($rows) { public function __construct(private array $rows) {} public function getAllForUser(string $uid, int $type): array { return $this->rows; } };
		$services = $this->createMock(MailServices::class);
		$services->method('draftsService')->willReturn(new \stdClass());
		$services->method('localMessageMapper')->willReturn($mapper);
		$services->method('localDraftType')->willReturn(1);
		$accounts = new MailGateway((new FakeMailApp())->withAccount(4, 'alice', 'Alice', 'alice@example.test'));
		$operations = new MailDraftOperations($services, $accounts, new PlanState((new InMemoryConfig())->mock($this)));

		$page = $operations->read(['limit' => 1, 'offset' => 1], 'alice');

		self::assertSame([2], array_column($page['drafts'], 'id'));
		self::assertTrue($page['hasMore']);
		self::assertSame(2, $page['next_offset']);
		$last = $operations->read(['limit' => 1, 'offset' => 2], 'alice');
		self::assertSame([3], array_column($last['drafts'], 'id'));
		self::assertFalse($last['hasMore']);
		self::assertNull($last['next_offset']);
	}

	public function testDraftReadIncludesAllWritableRecipientsAndFlagsOversizedNativeLists(): void {
		$recipient = new class { public function getType(): int { return 1; } public function getLabel(): string { return 'Person'; } public function getEmail(): string { return 'person@example.test'; } };
		$attachment = new class { public function getFileName(): string { return 'part.txt'; } public function getMimeType(): string { return 'text/plain'; } };
		foreach ([150, 151] as $count) {
			$draft = new MailDraftTestDouble(77, 4, 100, 'Before', 'Body', '', false, array_fill(0, 101, $attachment), array_fill(0, $count, $recipient));
			$service = new class($draft) { public function __construct(private object $draft) {} public function getMessage(int $id, string $uid): object { return $this->draft; } };
			$row = $this->operations($service)->read(['draft_id' => 77], 'alice')['draft'];
			self::assertCount(150, $row['recipients']['to']);
			self::assertSame($count > 150, $row['recipientsTruncated']);
			self::assertCount(100, $row['attachments']);
			self::assertTrue($row['attachmentsTruncated']);
		}
	}

	private function operations(object $draftService): MailDraftOperations {
		$services = $this->createMock(MailServices::class);
		$services->method('draftsService')->willReturn($draftService);
		$accounts = new MailGateway((new FakeMailApp())->withAccount(4, 'alice', 'Alice', 'alice@example.test'));
		return new MailDraftOperations($services, $accounts, new PlanState((new InMemoryConfig())->mock($this)));
	}
}

final class MailDraftTestDouble {
	public function __construct(private int $id, private int $accountId, private int $updatedAt, private string $subject,
		public string $bodyPlain, private string $bodyHtml, private bool $html, private array $attachments, private array $recipients) {}
	public function getId(): int { return $this->id; }
	public function getAccountId(): int { return $this->accountId; }
	public function getUpdatedAt(): int { return $this->updatedAt; }
	public function getSubject(): string { return $this->subject; }
	public function getBodyPlain(): string { return $this->bodyPlain; }
	public function getBodyHtml(): string { return $this->bodyHtml; }
	public function isHtml(): bool { return $this->html; }
	public function getAttachments(): array { return $this->attachments; }
	public function getRecipients(): array { return $this->recipients; }
}
