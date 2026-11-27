<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Operations\ActorIdentity;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Contracts\MailOwnedDraftSender;
use OCA\Mcp\Modules\Mail\Contracts\MailOwnedDraftStore;
use OCA\Mcp\Modules\Mail\Drafts\MailOwnedDraftOperations;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Access\MailAccessGuard;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Support\Dav\ScopeStub;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class MailOwnedDraftOperationsTest extends TestCase {
	public function testAConfirmedUpdateUsesOpaqueOwnedIdAndIncrementsTheCurrentRevision(): void {
		[$ops, $store] = $this->operations();
		$create = ['account_id' => 7, 'subject' => 'Hello', 'body_plain' => 'First draft', 'to' => [['email' => 'recipient@example.test']]];
		$plan = $ops->previewWrite($create, 'alice');
		$saved = $ops->write($create + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		self::assertSame(1, $saved['draft']['revision']);
		self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $saved['draft']['owned_draft_id']);
		self::assertSame('First draft', $saved['draft']['body_plain']);

		$id = $saved['draft']['owned_draft_id'];
		$update = ['account_id' => 7, 'owned_draft_id' => $id, 'revision' => 1, 'subject' => 'Hello again', 'body_plain' => 'Second draft'];
		$updatePlan = $ops->previewWrite($update, 'alice');
		self::assertSame(['owned_draft_id' => $id, 'account_id' => 7, 'revision' => 1], $updatePlan['before']);
		$updated = $ops->write($update + ['confirm' => true, 'plan_state' => $updatePlan['plan_state']], 'alice');
		self::assertSame(2, $updated['draft']['revision']);
		self::assertSame('Second draft', $updated['draft']['body_plain']);
		self::assertSame(2, $store->load('alice', str_repeat('a', 64))['version']);
	}

	public function testRepeatedCreateConfirmationCannotCreateAnotherDraftAfterOwnerRevisionAdvances(): void {
		[$ops] = $this->operations();
		$args = ['account_id' => 7, 'subject' => 'One', 'body_plain' => 'Initial'];
		$plan = $ops->previewWrite($args, 'alice');
		$confirmed = $args + ['confirm' => true, 'plan_state' => $plan['plan_state']];
		$ops->write($confirmed, 'alice');
		$this->expectException(PlanChanged::class);
		$ops->write($confirmed, 'alice');
	}

	public function testConfirmedDeleteRemovesOnlyTheMcpOwnedDraftAndNeverCallsNativeDraftServices(): void {
		[$ops, $store, , $app] = $this->operations();
		$create = ['account_id' => 7, 'subject' => 'Private', 'body_plain' => 'Local only'];
		$plan = $ops->previewWrite($create, 'alice');
		$draft = $ops->write($create + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice')['draft'];
		$deleteArgs = ['account_id' => 7, 'owned_draft_id' => $draft['owned_draft_id'], 'revision' => $draft['revision']];
		$deletePlan = $ops->previewDelete($deleteArgs, 'alice');
		self::assertSame(['owned_draft_id' => $draft['owned_draft_id'], 'account_id' => 7, 'revision' => 1], $deletePlan['draft']);
		$result = $ops->delete($deleteArgs + ['confirm' => true, 'plan_state' => $deletePlan['plan_state']], 'alice');
		self::assertSame($draft['owned_draft_id'], $result['deleted_owned_draft_id']);
		self::assertSame([], $store->load('alice', str_repeat('a', 64))['drafts']);
		self::assertSame(['AccountService::find'], array_values(array_unique($app->serviceCalls())));
	}

	public function testApprovalCannotCrossConnectionOrOverwriteAConcurrentOwnerSnapshot(): void {
		[$ops, $store, $connection] = $this->operations();
		$create = ['account_id' => 7, 'subject' => 'One', 'body_plain' => 'Initial'];
		$p = $ops->previewWrite($create, 'alice');
		$created = $ops->write($create + ['confirm' => true, 'plan_state' => $p['plan_state']], 'alice');
		$edit = ['account_id' => 7, 'owned_draft_id' => $created['draft']['owned_draft_id'], 'revision' => 1, 'subject' => 'Two', 'body_plain' => 'Changed'];
		$stale = $ops->previewWrite($edit, 'alice');
		$connection->set(9);
		try {
			$ops->write($edit + ['confirm' => true, 'plan_state' => $stale['plan_state']], 'alice');
			self::fail('The connection change must invalidate the prior plan.');
		} catch (PlanChanged) { self::assertSame('Initial', $ops->read(['account_id' => 7, 'owned_draft_id' => $created['draft']['owned_draft_id']], 'alice')['draft']['body_plain']); }

		$connection->set(8);
		$stale = $ops->previewWrite($edit, 'alice');
		$store->bumpVersion();
		$this->expectException(PlanChanged::class);
		$ops->write($edit + ['confirm' => true, 'plan_state' => $stale['plan_state']], 'alice');
	}

	public function testConfirmedSendClaimsTheDraftBeforeTransportAndCannotReplay(): void {
		$sender = $this->createMock(MailOwnedDraftSender::class);
		$sender->method('available')->willReturn(true);
		$sender->method('accountIdentity')->willReturn(['email' => 'alice@example.test', 'name' => 'Primary']);
		[$ops, $store] = $this->operations($sender);
		$args = $this->newDraft($ops);
		$plan = $ops->previewSend($args, 'alice');
		self::assertSame('Approved body', $plan['draft']['body_plain']);
		self::assertSame([['email' => 'recipient@example.test', 'label' => '']], $plan['draft']['to']);
		$sender->expects(self::once())->method('send')->willReturnCallback(
			static function (string $uid, object $account, array $draft, \Closure $authorize) use ($store): string {
				$authorize();
				self::assertSame([], $store->load($uid, str_repeat('a', 64))['drafts']);
				self::assertSame('uncertain', $store->findAttempt($uid, str_repeat('a', 64), 7, $draft['owned_draft_id'])['send_state']);
				self::assertSame('Approved body', $draft['body_plain']);
				return 'accepted';
			}
		);
		$confirmation = $args + ['confirm' => true, 'plan_state' => $plan['plan_state']];
		self::assertSame('accepted', $ops->send($confirmation, 'alice')['send_state']);
		self::assertSame('accepted', $ops->send($confirmation, 'alice')['send_state']);
	}

	public function testUncertainOutcomeStaysUncertainAcrossConnectionsAndCannotBeEdited(): void {
		$sender = $this->createMock(MailOwnedDraftSender::class);
		$sender->method('available')->willReturn(true);
		$sender->method('accountIdentity')->willReturn(['email' => 'alice@example.test', 'name' => 'Primary']);
		$sender->expects(self::once())->method('send')->willThrowException(new \RuntimeException('lost SMTP acknowledgement'));
		[$ops, , $connection] = $this->operations($sender);
		$args = $this->newDraft($ops);
		$plan = $ops->previewSend($args, 'alice');
		$confirmation = $args + ['confirm' => true, 'plan_state' => $plan['plan_state']];
		$connection->set(9);
		try {
			$ops->send($confirmation, 'alice');
			self::fail('The plan must bind the OAuth connection');
		} catch (PlanChanged) {
			$connection->set(8);
		}
		self::assertSame('uncertain', $ops->send($confirmation, 'alice')['send_state']);
		$connection->set(9);
		self::assertSame('uncertain', $ops->previewSend($args, 'alice')['send_state']);
		$this->expectException(ToolFailure::class);
		$ops->previewWrite($args + ['subject' => 'Changed', 'body_plain' => 'Changed'], 'alice');
	}

	public function testAChangedSendingIdentityNeedsASeparateConfirmation(): void {
		$sender = $this->createMock(MailOwnedDraftSender::class);
		$sender->method('available')->willReturn(true);
		$identity = ['email' => 'alice@example.test', 'name' => 'Primary', 'smtp_port' => 465];
		$sender->method('accountIdentity')->willReturnCallback(static function () use (&$identity): array { return $identity; });
		$sender->expects(self::never())->method('send');
		[$ops] = $this->operations($sender);
		$args = $this->newDraft($ops);
		$plan = $ops->previewSend($args, 'alice');
		self::assertArrayNotHasKey('smtp_port', $plan['account']);
		$identity['smtp_port'] = 587;
		$this->expectException(PlanChanged::class);
		$ops->send($args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
	}

	public function testMoreThanFiftyAttemptsLeaveActiveDraftSpaceWithoutDeletePermission(): void {
		$sender = $this->createMock(MailOwnedDraftSender::class);
		$sender->method('available')->willReturn(true);
		$sender->method('accountIdentity')->willReturn(['email' => 'alice@example.test', 'name' => 'Primary']);
		$attempts = 0;
		$sender->expects(self::exactly(52))->method('send')->willReturnCallback(
			static function () use (&$attempts): string { return ++$attempts % 2 === 0 ? 'uncertain' : 'accepted'; }
		);
		[$ops] = $this->operations($sender);
		for ($i = 0; $i < 52; $i++) {
			$args = $this->newDraft($ops);
			$plan = $ops->previewSend($args, 'alice');
			$first ??= [$args, $plan['plan_state']];
			$receipt = $ops->send($args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
			self::assertSame($i % 2 === 0 ? 'accepted' : 'uncertain', $receipt['send_state']);
		}
		self::assertSame([], $ops->read(['account_id' => 7], 'alice')['drafts']);
		[$old, $confirmation] = $first;
		unset($old['revision']);
		self::assertSame('accepted', $ops->read($old, 'alice')['draft']['send_state']);
		self::assertSame('accepted', $ops->send($first[0] + ['plan_state' => $confirmation], 'alice')['send_state']);
		self::assertSame('uncertain', $ops->read(['account_id' => 7, 'owned_draft_id' => $args['owned_draft_id']], 'alice')['draft']['send_state']);
		self::assertSame(52, $attempts);
	}

	private function newDraft(MailOwnedDraftOperations $ops): array {
		$input = ['account_id' => 7, 'subject' => 'New message', 'body_plain' => 'Approved body', 'to' => [['email' => 'recipient@example.test']]];
		$plan = $ops->previewWrite($input, 'alice');
		$draft = $ops->write($input + ['plan_state' => $plan['plan_state']], 'alice')['draft'];
		return ['account_id' => 7, 'owned_draft_id' => $draft['owned_draft_id'], 'revision' => 1];
	}

	private function operations(?MailOwnedDraftSender $sender = null): array {
		$store = new MemoryOwnedDraftStore();
		$actors = $this->createMock(ActorIdentity::class);
		$actors->method('capture')->with('alice')->willReturn(str_repeat('a', 64));
		$actors->method('assertCurrent');
		$app = (new FakeMailApp())->withAccount(7, 'alice', 'Primary', 'alice@example.test');
		$accounts = new MailGateway($app, new MailAccessGuard(new ScopeStub()));
		$fresh = static fn (string $uid, string $tool, int $account): \Closure => static fn () => null;
		$clock = $this->createMock(ITimeFactory::class);
		$time = 1233;
		$clock->method('getTime')->willReturnCallback(static function () use (&$time): int { return ++$time; });
		$config = (new InMemoryConfig())->mock($this);
		$plans = new PlanState($config);
		$connection = new ConnectionContext();
		$connection->set(8);
		$ops = new MailOwnedDraftOperations($store, $actors, $accounts, $plans, $clock, $connection, $fresh, static fn (): bool => true, $sender);
		return [$ops, $store, $connection, $app];
	}
}

final class MemoryOwnedDraftStore implements MailOwnedDraftStore {
	private array $drafts = [];
	private array $history = [];
	private int $version = 0;
	public function load(string $uid, string $actor): array { return ['version' => $this->version, 'drafts' => $this->drafts]; }
	public function compareAndSwap(string $uid, string $actor, int $expectedVersion, array $drafts, int $updatedAt): bool {
		if ($expectedVersion !== $this->version) return false;
		$this->drafts = [];
		foreach ($drafts as $draft) {
			if (isset($draft['send_state'])) {
				$this->history[$draft['owned_draft_id']] = $draft;
			} else {
				$this->drafts[] = $draft;
			}
		}
		$this->version++;
		return true;
	}
	public function findAttempt(string $uid, string $actor, int $account, string $id): ?array {
		return $this->history[$id] ?? null;
	}
	public function completeAttempt(string $uid, string $actor, int $account, string $id, int $revision, string $outcome): bool {
		if (($this->history[$id]['send_state'] ?? null) !== 'uncertain') {
			return false;
		}
		$this->history[$id]['send_state'] = $outcome;
		return true;
	}
	public function bumpVersion(): void { $this->version++; }
}
