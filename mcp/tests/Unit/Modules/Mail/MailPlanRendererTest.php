<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Modules\Mail\Presentation\MailPlanRenderer;
use OCA\Mcp\Tests\Support\Mail\MailWritePlanFixtures;
use PHPUnit\Framework\TestCase;

final class MailPlanRendererTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Mcp\AssertsReadablePlans;

    public function testEveryMailWriteHasAReadableInertPlan(): void {
        [$module, $plans] = MailWritePlanFixtures::create();

        $this->assertEveryWriteToolHasAReadablePlan($module, $plans);
    }

	public function testDraftPlanShowsLongBodyInertlyWithoutTruncatingIt(): void {
		$body = str_repeat('line with `ticks` and *markup* ', 30) . "\n# sender heading";
		$rendered = (new MailPlanRenderer())->render('mail_write_draft', [
			'action' => 'update', 'account' => ['email' => 'alice@example.test'], 'draft_id' => 77,
			'after' => ['subject' => 'Subject', 'body_plain' => $body, 'body_html' => '', 'is_html' => false,
				'to' => [['label' => 'Name', 'email' => 'person@example.test']], 'cc' => [], 'bcc' => []],
			'plan_state' => str_repeat('a', 64),
		]);

		self::assertIsString($rendered);
		self::assertStringContainsString('person@example.test', $rendered);
		self::assertStringContainsString('sender heading', $rendered);
		self::assertStringContainsString('markup', $rendered);
		self::assertGreaterThan(900, strlen($rendered));
		self::assertStringNotContainsString(str_repeat('a', 64), $rendered);
		self::assertStringNotContainsString("\n# sender heading", $rendered);
	}

	public function testAttachmentPlanOmitsInternalFingerprintAndNodeId(): void {
		$rendered = (new MailPlanRenderer())->render('mail_save_attachment', [
			'attachment' => ['name' => "report.pdf", 'message_id' => 42, 'mimeType' => 'application/pdf', 'size' => 12],
			'content_sha256' => str_repeat('b', 64), 'plan_state' => str_repeat('a', 64),
			'destination' => ['path' => '/Shared/report.pdf', 'folder' => '/Shared', 'destinationFolderId' => 999,
				'access' => ['scope' => 'shared', 'owner' => 'alice', 'ownerDisplayName' => 'Alice'],
				'shared' => [], 'requiresSharedConfirmation' => true],
		]);

		self::assertIsString($rendered);
		self::assertStringContainsString('/Shared/report.pdf', $rendered);
		self::assertStringContainsString('shared', $rendered);
		self::assertStringNotContainsString(str_repeat('b', 64), $rendered);
		self::assertStringNotContainsString(str_repeat('a', 64), $rendered);
		self::assertStringNotContainsString('999', $rendered);
	}
}
