<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\Attributes\Group;

/**
 * The two Mail tools against the real Mail app and a real IMAP server (Mail cells only, mail-cell.sh): they answer
 * from the cache of the Mail app, never reach the IMAP server and change no state, neither on the server nor in the
 * Mail tables; another user's account reads exactly like one that does not exist.
 *
 * Two canaries make "no synchronization" observable. After provisioning, a folder is created on the IMAP server
 * behind the Mail app's back, and the account's last folder synchronization is moved to the epoch, so that any
 * call into MailManager::getMailboxes() or MailboxSync::sync() would contact the server, store the new folder and
 * move that timestamp. Neither may happen.
 */
#[Group('mail')]
final class McpMailReadTest extends IntegrationTestCase {
    private ?MailFreshContentFixture $fresh=null;
	private array $toolPermissionSnapshot = [];

	/** Folder created on the server after Mail cached the account. */
    private ?string $canary=null;

	/** Account id no Mail account has. */
	private const MISSING = 987654321;

	/**
	 * Runs only in a Mail cell.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_MAIL') !== '1') {
			$this->markTestSkipped('Mail cell only (MCP_IT_WITH_MAIL=1)');
		}
		$this->toolPermissionSnapshot = \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::capture([MailActor::ALICE], ['mail_list_accounts', 'mail_list_mailboxes', 'mail_search_messages', 'mail_read_message']);
	}

	protected function tearDown(): void {
		try {
            if($this->fresh!==null){$this->fresh->close();$this->fresh=null;}
            if($this->canary!==null){$probe=$this->imap('alice');try{$probe->removeFixtureMailbox($this->canary);}finally{$probe->close();}$this->canary=null;}
			if ($this->toolPermissionSnapshot !== []) { \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::restore($this->toolPermissionSnapshot); }
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Both tools answer from the cache, alice never reaches bob's account, and nothing changes anywhere.
	 *
	 * @return void
	 */
	public function testTheToolsReadTheCacheAndChangeNothing(): void {
		\OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign(MailActor::ALICE, ['mail_list_accounts' => 'allow', 'mail_list_mailboxes' => 'allow']);
        $alice = MailActor::run(MailActor::ALICE, 'accounts')['accounts'];
		$bob = MailActor::run(MailActor::BOB, 'accounts')['accounts'];
		$this->assertCount(1, $alice);
		$this->assertCount(1, $bob);
		$own = $alice[0]['id'];
		$foreign = $bob[0]['id'];

		$imap = $this->imap('alice');
        $this->canary='mcp-cache-canary-'.bin2hex(random_bytes(8));
		$imap->create($this->canary);
		$this->resetLastMailboxSync($own);
		$serverBefore = $imap->state();
		$cacheBefore = $this->cache($own);

		$out = MailActor::run(MailActor::ALICE, 'observe', ['own' => $own, 'foreign' => $foreign, 'missing' => self::MISSING]);

		$this->assertSame($serverBefore, $imap->state(), 'the IMAP server changed');
		$imap->close();
		$this->assertSame(hash('sha256', json_encode($cacheBefore, JSON_THROW_ON_ERROR)), hash('sha256', json_encode($this->cache($own), JSON_THROW_ON_ERROR)), 'the Mail tables changed');

		$this->assertSame(['mail_list_accounts', 'mail_list_mailboxes'], array_values(array_filter($out['tools'], static fn (string $tool): bool => str_starts_with($tool, 'mail_'))));
		$accounts = $this->decoded($out['accounts']);
		$this->assertSame([['id' => $own, 'name' => 'Mail of alice', 'email' => 'alice@example.com']], $accounts['accounts']);

		$mailboxes = $this->decoded($out['own']);
		$byPath = array_column($mailboxes['mailboxes'], null, 'path');
		$this->assertArrayNotHasKey($this->canary, $byPath, 'a folder only the server has was listed: the tool synchronized');
		$this->assertArrayHasKey($this->canary, $serverBefore);
		foreach (['INBOX', 'Sent', 'Archive', 'Projects', 'Projects/2026'] as $path) {
			$this->assertArrayHasKey($path, $byPath, "folder $path");
		}
		$this->assertSame('INBOX', $mailboxes['mailboxes'][0]['path']);
		$this->assertSame(['special' => 'inbox', 'unread' => 2, 'total' => 3], array_intersect_key($byPath['INBOX'], ['special' => 0, 'unread' => 0, 'total' => 0]));
		$this->assertSame('sent', $byPath['Sent']['special']);
		$this->assertSame('archive', $byPath['Archive']['special']);
		$this->assertSame('2026', $byPath['Projects/2026']['name']);
		$this->assertStringNotContainsString('password', json_encode($out, JSON_THROW_ON_ERROR));
		$this->assertStringNotContainsString('inbound', json_encode($out, JSON_THROW_ON_ERROR));

		$this->assertTrue($out['foreign']['isError'] ?? false);
		$this->assertSame('Mail account not found.', $out['foreign']['content'][0]['text']);
		$this->assertSame($out['missing'], $out['foreign']);
	}

	/** Reading an unread message uses BODY.PEEK, while searches and reads cannot cross account ownership. */
	public function testMessageSearchAndBodyReadPreserveUnreadStateAndAccountIsolation(): void {
		$policy = \OCP\Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		$policy->setEligible(MailActor::ALICE, true);
		$policy->setConnected(MailActor::ALICE, true);
		\OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign(MailActor::ALICE, ['mail_list_accounts' => 'allow', 'mail_search_messages' => 'allow', 'mail_read_message' => 'allow']);
		$accounts = MailActor::run(MailActor::ALICE, 'accounts')['accounts'];
		$foreignAccounts = MailActor::run(MailActor::BOB, 'accounts')['accounts'];
		$this->assertCount(1, $accounts);
		$this->assertCount(1, $foreignAccounts);
		$ownAccount = (int)$accounts[0]['id'];
		$foreignAccount = (int)$foreignAccounts[0]['id'];
        if(\OCP\Server::get(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class)->currentContentGeneration())$this->fresh=new MailFreshContentFixture(MailActor::ALICE,['Unread message'],'fresh-'.bin2hex(random_bytes(12)));
		$inbox = $this->mailboxId($ownAccount, 'INBOX');
		$foreignMessage = $this->messageId((int)$foreignAccounts[0]['id'], 'Message of bob');
		$unreadId = $this->messageId($ownAccount, 'Unread message');

		$imap = $this->imap('alice');
		$before = $imap->state();
        $boxName=$this->fresh?->mailboxName??'INBOX';$remote=$this->fresh?->remoteUids['Unread message']??3;
		$this->assertNotContains('\\Seen', $before[$boxName]['flags'][$remote] ?? [], 'the selected fixture message must begin unread');
		$client = new \OCA\Mcp\Tests\Integration\Support\McpClient(\OCA\Mcp\Tests\Integration\Support\LoginFactory::basic(MailActor::ALICE));
		$ownSearch = $client->tool('mail_search_messages', ['mailbox_id' => $inbox, 'unread' => true]);
		$this->assertTrue($ownSearch->ok(), $ownSearch->describe());
		$ids = array_column($ownSearch->json()['messages'], 'id');
		$row=MailSignatureModeActor::row('mail_messages',$unreadId);
        $this->assertContains($unreadId, $ids,json_encode(['ids'=>$ids,'seen'=>$row['flag_seen'],'deleted'=>$row['flag_deleted'],'mailbox'=>$row['mailbox_id'],'selected'=>$inbox,'thread'=>$row['thread_root_id']!==null]));
		$this->assertNotContains($foreignMessage, $ids);
		$foreignSearch = $client->tool('mail_search_messages', ['account_id' => $foreignAccount]);
		$missingSearch = $client->tool('mail_search_messages', ['account_id' => 987654321]);
		$this->assertTrue($foreignSearch->isError());
		$this->assertSame($missingSearch->fingerprint(), $foreignSearch->fingerprint());
		$body = $client->tool('mail_read_message', ['message_id' => $unreadId]);
		$this->assertTrue($body->ok(), $body->describe());
		$this->assertStringContainsString('Body of Unread message.', $body->text());
		$foreignRead = $client->tool('mail_read_message', ['message_id' => $foreignMessage]);
		$missingRead = $client->tool('mail_read_message', ['message_id' => 987654321]);
		$this->assertTrue($foreignRead->isError());
		$this->assertSame($missingRead->fingerprint(), $foreignRead->fingerprint());
		$this->assertSame($before, $imap->state(), 'mail search or body read changed IMAP flags or unread state');
		$imap->close();
	}

	/**
	 * A logged-in probe of alice's or bob's mailbox on the IMAP server of the cell.
	 *
	 * @param string $user IMAP user
	 * @return ImapProbe the probe
	 */
	private function imap(string $user): ImapProbe {
		return new ImapProbe((string)getenv('MCP_IT_IMAP_HOST'), (int)getenv('MCP_IT_IMAP_PORT'), $user, (string)getenv('MCP_IT_IMAP_PASSWORD'));
	}

	/**
	 * Marks the account as never synchronized, so that any folder synchronization would run and move the timestamp.
	 *
	 * @param int $accountId Mail account id
	 * @return void
	 */
	private function resetLastMailboxSync(int $accountId): void {
		$qb = $this->official()->getQueryBuilder();
		$qb->update('mail_accounts')
			->set('last_mailbox_sync', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	private function mailboxId(int $accountId, string $path): int {
        if($this->fresh!==null&&$path==='INBOX')return $this->fresh->mailboxId;
		$qb = $this->official()->getQueryBuilder();
		$result = $qb->select('id')->from('mail_mailboxes')
			->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($path)))
			->executeQuery();
		try {
			return (int)$result->fetchOne();
		} finally {
			$result->closeCursor();
		}
	}

	private function messageId(int $accountId, string $subject): int {
        if($this->fresh!==null&&isset($this->fresh->messages[$subject]))return $this->fresh->messages[$subject];
		$qb = $this->official()->getQueryBuilder();
		$qb->select('m.id')->from('mail_messages', 'm')->innerJoin('m', 'mail_mailboxes', 'b', 'm.mailbox_id = b.id')
			->where($qb->expr()->eq('b.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('m.subject', $qb->createNamedParameter($subject)));
		$result = $qb->executeQuery();
		try {
			return (int)$result->fetchOne();
		} finally {
			$result->closeCursor();
		}
	}

	/**
	 * Every row the Mail app keeps for the account: the account, its folders and their messages.
	 *
	 * @param int $accountId Mail account id
	 * @return array{account:list<array<string, mixed>>, mailboxes:list<array<string, mixed>>, messages:list<array<string, mixed>>} the rows
	 */
	private function cache(int $accountId): array {
		$account = $this->rows('mail_accounts', 'id', [$accountId]);
		$mailboxes = $this->rows('mail_mailboxes', 'account_id', [$accountId]);
		$messages = $this->rows('mail_messages', 'mailbox_id', array_map(static fn (array $row): int => (int)$row['id'], $mailboxes));
		$this->assertSame(0, (int)$account[0]['last_mailbox_sync']);
		$this->assertNotSame([], $mailboxes, 'the provisioning synchronized the folders');
		return ['account' => $account, 'mailboxes' => $mailboxes, 'messages' => $messages];
	}

	/**
	 * All columns of the rows of a table whose column is one of the values, ordered by id.
	 *
	 * @param string $table table without prefix
	 * @param string $column filtered column
	 * @param list<int> $values accepted values
	 * @return list<array<string, mixed>> the rows
	 */
	private function rows(string $table, string $column, array $values): array {
		if ($values === []) {
			return [];
		}
		$qb = $this->official()->getQueryBuilder();
		$result = $qb->select('*')->from($table)
			->where($qb->expr()->in($column, $qb->createNamedParameter($values, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id')
			->executeQuery();
		try {
			return $result->fetchAll();
		} finally {
			$result->closeCursor();
		}
	}

	/**
	 * The JSON answer of a successful tool call.
	 *
	 * @param array<string, mixed> $result MCP result
	 * @return array<string, mixed> the decoded answer
	 */
	private function decoded(array $result): array {
		$this->assertFalse($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));
		return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
	}
}
