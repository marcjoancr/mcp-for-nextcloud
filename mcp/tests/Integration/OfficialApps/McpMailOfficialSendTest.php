<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\OfficialApps;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolReply;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class McpMailOfficialSendTest extends TestCase {
	private const SEND = 'mail_send_owned_draft';
	private const SMTP = '/tmp/mcp-official-send-smtp';

	public function testStockMailSendsAnApprovedDraftOnlyOnceAndRetainsUnknownAcknowledgement(): void {
		if (getenv('MCP_IT_OFFICIAL_MAIL_SEND') !== '1') {
			self::markTestSkipped('Dedicated official Mail and MariaDB cell required.');
		}
		$db = Server::get(IDBConnection::class);
		$config = Server::get(IConfig::class);
		self::assertSame(IDBConnection::PLATFORM_MARIADB, $db->getDatabaseProvider(true));
		self::assertTrue($config->getSystemValueBool('app.mcp.official-apps.mail'));
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		$alice = 'mcp-it-alice';
		$bob = 'mcp-it-bob';
		foreach ([$alice, $bob] as $uid) {
			$policy->setEligible($uid, true);
			$policy->setConnected($uid, true);
			$this->grant($uid, [ToolGrantsMapper::SERVICE => 'allow', 'mail_list_accounts' => 'allow',
				'mail_read_owned_drafts' => 'allow', 'mail_write_owned_draft' => 'allow', self::SEND => 'allow']);
		}
		$config->setSystemValue('app.mail.mcp-transmission.enabled', true);
		try {
			$sender = new McpClient(LoginFactory::bearer($alice));
			$secondConnection = new McpClient(LoginFactory::bearer($alice));
			$outsider = new McpClient(LoginFactory::bearer($bob));
			$account = $this->payload($sender->tool('mail_list_accounts'))['accounts'][0]['id'];
			$foreignAccount = $this->payload($outsider->tool('mail_list_accounts'))['accounts'][0]['id'];
			$upgrade = getenv('MCP_IT_OFFICIAL_MAIL_UPGRADE') === '1';
			$legacy = ['account_id' => $account, 'owned_draft_id' => str_repeat('7', 32)];
			if ($upgrade) {
				self::assertSame('uncertain', $this->payload($sender->tool('mail_read_owned_drafts', $legacy))['draft']['send_state']);
				$preserved = $this->payload($outsider->tool('mail_read_owned_drafts', ['account_id' => $foreignAccount]));
				self::assertSame('Preserved active 2.11.0 draft', $preserved['drafts'][0]['subject']);
			}
			$initial = $this->recordings();
			$message = $this->draft($sender, $account, 'Official SMTP acceptance');
			$this->mode('accept');
			$plan = $this->payload($sender->tool(self::SEND, $message));
			self::assertTrue($plan['requiresConfirmation']);
			self::assertSame('alice@example.test', $plan['account']['email']);
			self::assertSame('Official SMTP acceptance', $plan['draft']['subject']);
			self::assertSame('Fixture text', $plan['draft']['body_plain']);
			self::assertFalse($outsider->tool(self::SEND, $message)->ok());
			self::assertFalse($outsider->tool(self::SEND, array_replace($message, ['account_id' => $foreignAccount]))->ok());
			self::assertFalse((new McpClient(LoginFactory::basic($alice)))->tool(self::SEND, $message)->ok());
			$confirmation = $message + ['plan_state' => $plan['plan_state']];
			$unbound = $secondConnection->write(self::SEND, $confirmation);
			self::assertTrue(!$unbound->ok() || ($unbound->structured()['requiresConfirmation'] ?? false));
			self::assertCount(count($initial), $this->recordings());
			$this->grant($alice, [self::SEND => 'deny']);
			self::assertFalse($sender->write(self::SEND, $confirmation)->ok());
			self::assertCount(count($initial), $this->recordings());
			$this->grant($alice, [self::SEND => 'allow']);
			$plan = $this->payload($sender->tool(self::SEND, $message));
			$confirmation = $message + ['plan_state' => $plan['plan_state']];
			self::assertSame('accepted', $this->payload($sender->write(self::SEND, $confirmation))['send_state']);
			self::assertSame('accepted', $this->payload($sender->write(self::SEND, $confirmation))['send_state']);
			$records = $this->recordings();
			self::assertCount(count($initial) + 1, $records);
			$record = end($records);
			self::assertTrue($record['acknowledged']);
			self::assertSame(2, $record['recipients']);
			$wire = (string)file_get_contents(self::SMTP . '/message-' . $record['id'] . '.eml');
			self::assertStringContainsString('Official SMTP acceptance', $wire);
			self::assertDoesNotMatchRegularExpression('/(?:^|\r\n)Bcc:/i', $wire);
			self::assertStringNotContainsString('blind@example.test', $wire);

			$uncertain = $this->draft($sender, $account, 'Official SMTP lost acknowledgement');
			$this->mode('ack_lost');
			$plan = $this->payload($sender->tool(self::SEND, $uncertain));
			$confirmation = $uncertain + ['plan_state' => $plan['plan_state']];
			self::assertSame('uncertain', $this->payload($sender->write(self::SEND, $confirmation))['send_state']);
			self::assertSame('uncertain', $this->payload($sender->write(self::SEND, $confirmation))['send_state']);
			self::assertFalse($secondConnection->tool(self::SEND, $uncertain)->ok());
			$read = $this->payload($sender->tool('mail_read_owned_drafts', array_diff_key($uncertain, ['revision' => true])));
			self::assertSame('uncertain', $read['draft']['send_state']);
			$records = $this->recordings();
			self::assertCount(count($initial) + 2, $records);
			self::assertFalse(end($records)['acknowledged']);

			self::assertFalse($outsider->tool('mail_read_owned_drafts', ['account_id' => $foreignAccount, 'owned_draft_id' => $uncertain['owned_draft_id']])->ok());
			$this->mode('accept');
			for ($index = 3; $index <= 51; $index++) {
				$next = $this->draft($sender, $account, 'Official SMTP quota ' . $index);
				$plan = $this->payload($sender->tool(self::SEND, $next));
				self::assertSame('accepted', $this->payload($sender->write(self::SEND, $next + ['plan_state' => $plan['plan_state']]))['send_state']);
			}
			self::assertCount(count($initial) + 51, $this->recordings());
			self::assertSame([], $this->payload($sender->tool('mail_read_owned_drafts', ['account_id' => $account]))['drafts']);
			self::assertSame('uncertain', $this->payload($sender->tool('mail_read_owned_drafts', array_diff_key($uncertain, ['revision' => true])))['draft']['send_state']);
			self::assertSame('accepted', $this->payload($sender->tool('mail_read_owned_drafts', array_diff_key($message, ['revision' => true])))['draft']['send_state']);
			if ($upgrade) {
				self::assertSame('uncertain', $this->payload($sender->tool('mail_read_owned_drafts', $legacy))['draft']['send_state']);
				$mapper = Server::get(\OCA\Mcp\Db\MailOwnedDraftsMapper::class);
				$actor = Server::get(\OCA\Mcp\Mcp\Operations\ActorIdentity::class)->capture($alice);
				self::assertNotNull($mapper->findAttempt($alice, $actor, $account, $legacy['owned_draft_id']));
			}

			$query = $db->getQueryBuilder();
			$count = $query->select($query->func()->count('*'))->from('mail_local_messages')
				->where($query->expr()->eq('account_id', $query->createNamedParameter($account)))->executeQuery();
			try {
				self::assertSame(0, (int)$count->fetchOne(), 'The native outbox must never see an MCP send.');
			} finally {
				$count->closeCursor();
			}
		} finally {
			$config->setSystemValue('app.mail.mcp-transmission.enabled', false);
			$this->mode('accept');
		}
	}

	private function draft(McpClient $client, int $account, string $subject): array {
		$input = ['account_id' => $account, 'subject' => $subject, 'body_plain' => 'Fixture text',
			'to' => [['email' => 'recipient@example.test']], 'bcc' => [['email' => 'blind@example.test']]];
		$plan = $this->payload($client->tool('mail_write_owned_draft', $input));
		$draft = $this->payload($client->write('mail_write_owned_draft', $input + ['plan_state' => $plan['plan_state']]))['draft'];
		return ['account_id' => $account, 'owned_draft_id' => $draft['owned_draft_id'], 'revision' => $draft['revision']];
	}

	private function grant(string $uid, array $decisions): void {
		$mapper = Server::get(ToolGrantsMapper::class);
		Server::get(PolicyTransaction::class)->runInternal([$uid], static fn () => null, static function () use ($uid, $mapper, $decisions): bool {
			foreach ($decisions as $tool => $decision) {
				$mapper->set(ToolGrantsMapper::USER, $uid, $tool, $decision);
			}
			return true;
		});
	}

	private function payload(ToolReply $reply): array {
		self::assertTrue($reply->ok(), $reply->describe());
		return $reply->structured() ?: $reply->json();
	}

	private function mode(string $mode): void {
		file_put_contents(self::SMTP . '/mode.json', json_encode([
			'fixture' => 'mcp-synthetic-delivery-v1', 'port' => 33466, 'mode' => $mode,
		], JSON_THROW_ON_ERROR));
	}

	private function recordings(): array {
		return json_decode((string)file_get_contents(self::SMTP . '/state.json'), true, 16, JSON_THROW_ON_ERROR)['messages'];
	}
}
