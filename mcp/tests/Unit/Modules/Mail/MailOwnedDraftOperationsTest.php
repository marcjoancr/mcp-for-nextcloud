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

	private function operations(): array {
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
		$ops = new MailOwnedDraftOperations($store, $actors, $accounts, $plans, $clock, $connection, $fresh, static fn (): bool => true);
		return [$ops, $store, $connection, $app];
	}
}

final class MemoryOwnedDraftStore implements MailOwnedDraftStore {
	private array $drafts = [];
	private int $version = 0;
	public function load(string $uid, string $actor): array { return ['version' => $this->version, 'drafts' => $this->drafts]; }
	public function compareAndSwap(string $uid, string $actor, int $expectedVersion, array $drafts, int $updatedAt): bool {
		if ($expectedVersion !== $this->version) return false;
		$this->drafts = $drafts;
		$this->version++;
		return true;
	}
	public function bumpVersion(): void { $this->version++; }
}
