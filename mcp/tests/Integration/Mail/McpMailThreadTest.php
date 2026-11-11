<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Native Mail thread reads remain scoped to the requesting user's account and do not alter IMAP flags. */
#[Group('mail')]
final class McpMailThreadTest extends IntegrationTestCase {
	public function testThreadPagingIsAccountScopedAndDoesNotChangeImapState(): void {
		if (getenv('MCP_IT_WITH_MAIL') !== '1') {
			$this->markTestSkipped('Mail cell only (MCP_IT_WITH_MAIL=1)');
		}
		$policy = Server::get(GrantPolicy::class);
		$config = Server::get(IConfig::class);
		$snapshot = [];
		foreach ([MailActor::ALICE, MailActor::BOB] as $uid) {
			foreach ([GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY, 'grant_mail_read', 'grant_mail_read_message'] as $key) {
				$snapshot[$uid][$key] = $config->getUserValue($uid, 'mcp', $key, '__missing__');
			}
		}
		$serviceBefore = $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '__missing__');
		$toolSnapshot = \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::capture([MailActor::ALICE, MailActor::BOB], ['mail_read_thread']);
		$aliceImap = new ImapProbe((string)getenv('MCP_IT_IMAP_HOST'), (int)getenv('MCP_IT_IMAP_PORT'), 'alice', (string)getenv('MCP_IT_IMAP_PASSWORD'));
        $fresh=[];
		$bobImap = new ImapProbe((string)getenv('MCP_IT_IMAP_HOST'), (int)getenv('MCP_IT_IMAP_PORT'), 'bob', (string)getenv('MCP_IT_IMAP_PASSWORD'));
		try {
			$policy->setGlobalEnabled(true);
			foreach ([MailActor::ALICE, MailActor::BOB] as $uid) {
				$policy->setEligible($uid, true);
				$policy->setConnected($uid, true);
				\OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign($uid, ['mail_read_thread' => 'allow']);
			}
            if(Server::get(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class)->currentContentGeneration()){$key='fresh-thread-'.bin2hex(random_bytes(12));$fresh['alice']=new MailFreshContentFixture(MailActor::ALICE,['Seen message','Flagged message'],$key);$fresh['bob']=new MailFreshContentFixture(MailActor::BOB,['Message of bob'],$key);}
			$aliceState = $aliceImap->state();
			$bobState = $bobImap->state();
			self::assertNotSame([], array_filter($aliceState['INBOX']['flags'], static fn (array $flags): bool => !in_array('\\Seen', $flags, true)), 'Alice must have unread messages before reading');
			self::assertNotSame([], array_filter($bobState['INBOX']['flags'], static fn (array $flags): bool => !in_array('\\Seen', $flags, true)), 'Bob must have unread messages before reading');
			$aliceAccount = $this->account(MailActor::ALICE);
			$bobAccount = $this->account(MailActor::BOB);
			$aliceRoot = $this->messageId($aliceAccount, 'Seen message');
			$aliceReply = $this->messageId($aliceAccount, 'Flagged message');
			$bobDuplicate = $this->messageId($bobAccount, 'Message of bob');
            if($fresh!==[]){$aliceRoot=$fresh['alice']->messages['Seen message'];$aliceReply=$fresh['alice']->messages['Flagged message'];$bobDuplicate=$fresh['bob']->messages['Message of bob'];}
			$alice = new McpClient(LoginFactory::basic(MailActor::ALICE));
			$bob = new McpClient(LoginFactory::basic(MailActor::BOB));

			$first = $alice->tool('mail_read_thread', ['message_id' => $aliceRoot, 'limit' => 1]);
			self::assertTrue($first->ok(), $first->describe());
			$page = $this->data($first);
			self::assertSame(1, $page['count'] ?? null);
			self::assertSame($aliceRoot, $page['messageId'] ?? null);
			self::assertTrue($page['hasMore'] ?? false);
			self::assertSame($aliceReply, $page['next_before_message_id'] ?? null, 'the exclusive cursor points at the returned message for the older page');
			self::assertSame('Flagged message', $page['messages'][0]['subject'] ?? null);
			self::assertStringContainsString('Body of Flagged message.', $page['messages'][0]['body']['text'] ?? '');
			self::assertSame($aliceState, $aliceImap->state(), 'first-page read changed Alice IMAP state');
			self::assertSame($bobState, $bobImap->state(), 'first-page read changed Bob IMAP state');

			$older = $alice->tool('mail_read_thread', ['message_id' => $aliceRoot, 'before_message_id' => $aliceReply, 'limit' => 1]);
			self::assertTrue($older->ok(), $older->describe());
			$olderPage = $this->data($older);
			self::assertSame(1, $olderPage['count'] ?? null);
			self::assertSame('Seen message', $olderPage['messages'][0]['subject'] ?? null);
			self::assertFalse($olderPage['hasMore'] ?? true);
			self::assertNull($olderPage['next_before_message_id'] ?? null);
			self::assertStringNotContainsString('Body of Message of bob.', json_encode([$page, $olderPage], JSON_THROW_ON_ERROR));

			$bobThread = $bob->tool('mail_read_thread', ['message_id' => $bobDuplicate]);
			self::assertTrue($bobThread->ok(), $bobThread->describe());
			self::assertSame(['Message of bob'], array_column($this->data($bobThread)['messages'], 'subject'));
			foreach ([[$alice, $bobDuplicate], [$bob, $aliceRoot]] as [$client, $id]) {
				$reply = $client->tool('mail_read_thread', ['message_id' => $id]);
				self::assertTrue($reply->isError(), $reply->describe());
				$missing = $client->tool('mail_read_thread', ['message_id' => 987654321]);
				self::assertSame($missing->fingerprint(), $reply->fingerprint(), 'foreign account messages must be indistinguishable from missing IDs');
			}
			$foreignCursor = $alice->tool('mail_read_thread', ['message_id' => $aliceRoot, 'before_message_id' => $bobDuplicate]);
			$missingCursor = $alice->tool('mail_read_thread', ['message_id' => $aliceRoot, 'before_message_id' => 987654321]);
			self::assertTrue($foreignCursor->isError(), $foreignCursor->describe());
			self::assertSame($missingCursor->fingerprint(), $foreignCursor->fingerprint(), 'foreign-thread cursors must be indistinguishable from missing cursors');
			self::assertSame($aliceState, $aliceImap->state(), 'thread reads changed Alice IMAP flags/modseq');
			self::assertSame($bobState, $bobImap->state(), 'thread reads changed Bob IMAP flags/modseq');
		} finally {
            foreach($fresh as$fixture)$fixture->close();
			$aliceImap->close();
			$bobImap->close();
			if ($serviceBefore === '__missing__') {
				$config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY);
			} else {
				$config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $serviceBefore);
			}
			\OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::restore($toolSnapshot);
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

	private function account(string $uid): int {
		$qb = $this->official()->getQueryBuilder();
		$result = $qb->select('id')->from('mail_accounts')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->executeQuery();
		try { return (int)$result->fetchOne(); } finally { $result->closeCursor(); }
	}

	private function messageId(int $account, string $subject): int {
		$qb = $this->official()->getQueryBuilder();
		$result = $qb->select('m.id')->from('mail_messages', 'm')->innerJoin('m', 'mail_mailboxes', 'b', 'm.mailbox_id = b.id')
			->where($qb->expr()->eq('b.account_id', $qb->createNamedParameter($account, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('m.subject', $qb->createNamedParameter($subject)))->executeQuery();
		try { return (int)$result->fetchOne(); } finally { $result->closeCursor(); }
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
