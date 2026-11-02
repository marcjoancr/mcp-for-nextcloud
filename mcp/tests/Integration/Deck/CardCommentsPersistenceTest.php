<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Native comments require card visibility, preview exact text, and recheck ACL at confirmation. */
#[Group('deck')]
final class CardCommentsPersistenceTest extends IntegrationTestCase {
	public function testVisibleAndPrivateCardsAndConfirmedCommentWrites(): void {
		$owner = DeckFixture::OWNER;
		$reader = DeckFixture::READER;
		$config = Server::get(IConfig::class);
		$policy = Server::get(GrantPolicy::class);
		$snapshot = [];
		foreach ([$owner, $reader] as $uid) {
			foreach ([GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY, 'grant_deck_read_comments', 'grant_deck_comment'] as $key) {
				$snapshot[$uid][$key] = $config->getUserValue($uid, 'mcp', $key, '__missing__');
			}
		}
		$serviceBefore = $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '__missing__');
		$fixture = null;
		try {
			$policy->setGlobalEnabled(true);
			foreach ([$owner, $reader] as $uid) {
				$policy->setEligible($uid, true);
				$policy->setConnected($uid, true);
				$policy->setGrant($uid, 'deck', 'read_comments', true);
				$policy->setGrant($uid, 'deck', 'comment', true);
			}
			$fixture = DeckFixture::run($owner, 'comment-seed');
			$ownerClient = new McpClient(LoginFactory::basic($owner));
			$readerClient = new McpClient(LoginFactory::basic($reader));

			$visible = $readerClient->tool('deck_list_comments', ['cardId' => $fixture['visibleCard']]);
			self::assertTrue($visible->ok(), $visible->describe());
			self::assertSame([], $this->data($visible)['comments'] ?? null);
			$private = $readerClient->tool('deck_list_comments', ['cardId' => $fixture['privateCard']]);
			self::assertTrue($private->isError(), $private->describe());
			self::assertSame(0, DeckFixture::run($owner, 'comment-count', ['card' => $fixture['visibleCard']])['count']);

			$message = 'Exact approved comment: preserve this text.';
			$args = ['cardId' => $fixture['visibleCard'], 'message' => $message];
			$planReply = $readerClient->tool('deck_add_comment', $args);
			self::assertTrue($planReply->ok(), $planReply->describe());
			$plan = $this->data($planReply);
			self::assertSame($message, $plan['commentText'] ?? null, 'preview must display the exact comment text');
			self::assertArrayHasKey('plan_state', $plan);
			self::assertSame(0, DeckFixture::run($owner, 'comment-count', ['card' => $fixture['visibleCard']])['count'], 'preview must not write a comment');

			$changedText = $readerClient->tool('deck_add_comment', ['cardId' => $fixture['visibleCard'], 'message' => 'Altered text',
				'confirm' => true, 'confirm_shared' => true, 'plan_state' => $plan['plan_state']]);
			self::assertTrue($changedText->ok(), $changedText->describe());
			self::assertTrue($this->data($changedText)['requiresConfirmation'] ?? false, 'a changed payload must yield a fresh preview, not execute');
			$changedCard = $readerClient->tool('deck_add_comment', ['cardId' => $fixture['privateCard'], 'message' => $message,
				'confirm' => true, 'confirm_shared' => true, 'plan_state' => $plan['plan_state']]);
			self::assertTrue($changedCard->isError(), $changedCard->describe());
			self::assertSame(0, DeckFixture::run($owner, 'comment-count', ['card' => $fixture['visibleCard']])['count']);

			$created = $readerClient->tool('deck_add_comment', $args + ['confirm' => true, 'confirm_shared' => true, 'plan_state' => $plan['plan_state']]);
			self::assertTrue($created->ok(), $created->describe());
			self::assertSame($message, $this->data($created)['comment']['message'] ?? $this->data($created)['message'] ?? null);
			$listed = $readerClient->tool('deck_list_comments', ['cardId' => $fixture['visibleCard']]);
			self::assertSame([$message], array_column($this->data($listed)['comments'] ?? [], 'message'));
			self::assertSame(1, DeckFixture::run($owner, 'comment-count', ['card' => $fixture['visibleCard']])['count']);

			$revocationText = 'This must not be written after access is revoked.';
			$revocationPlan = $readerClient->tool('deck_add_comment', ['cardId' => $fixture['visibleCard'], 'message' => $revocationText]);
			self::assertTrue($revocationPlan->ok(), $revocationPlan->describe());
			$revocation = $this->data($revocationPlan);
			self::assertSame($revocationText, $revocation['commentText'] ?? null);
			DeckFixture::run($owner, 'comment-revoke', $fixture);
			$denied = $readerClient->tool('deck_add_comment', ['cardId' => $fixture['visibleCard'], 'message' => $revocationText,
				'confirm' => true, 'confirm_shared' => true, 'plan_state' => $revocation['plan_state']]);
			self::assertTrue($denied->isError(), $denied->describe());
			self::assertSame(1, DeckFixture::run($owner, 'comment-count', ['card' => $fixture['visibleCard']])['count'], 'revoked permission must leave native comments unchanged');
		} finally {
			if ($fixture !== null) {
				DeckFixture::run($owner, 'comment-cleanup', $fixture);
			}
			if ($serviceBefore === '__missing__') {
				$config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY);
			} else {
				$config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $serviceBefore);
			}
			foreach ($snapshot as $uid => $values) {
				foreach ($values as $key => $value) {
					if ($value === '__missing__') {
						$config->deleteUserValue($uid, 'mcp', $key);
					} else {
						$config->setUserValue($uid, 'mcp', $key, $value);
					}
				}
			}
		}
	}

	/** @return array<string, mixed> */
	private function data(\OCA\Mcp\Tests\Integration\Support\ToolReply $reply): array {
		$data = $reply->structured();
		if ($data === []) {
			$data = $reply->json();
		}
		self::assertIsArray($data, $reply->describe());
		return $data;
	}
}
