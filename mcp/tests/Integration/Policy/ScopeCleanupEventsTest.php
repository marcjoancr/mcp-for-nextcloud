<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Service\Policy\ScopeCleanup;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use OCP\Server;
use OCP\User\Events\UserDeletedEvent;

/**
 * The account cleanup of the resource scope (contracts B.3, F3, validation S12) against the real server: the real
 * deletion events of a real account reach the app's listener, which removes the scope the account had when its deletion
 * began and nothing saved after it; the 15-minute job removes the scope of an account that is gone and keeps that of
 * one that exists. The scope rows are written the way a save writes them (the writer's transaction, policy_rev = the
 * revision of the commit), without the native-resource validation of the admin route, which is not under test here.
 */
final class ScopeCleanupEventsTest extends IntegrationTestCase {
    /** @var list<string> accounts created by a test, deleted at the end */
    private array $created = [];
    /** @var list<array{string, callable}> listeners added by a test */
    private array $listeners = [];
    /** @var list<array{string, string}> uid and module of scope rows a test may leave behind */
    private array $touched = [];

    protected function tearDown(): void {
        foreach ($this->listeners as [$event, $listener]) {
            Server::get(IEventDispatcher::class)->removeListener($event, $listener);
        }
        foreach ($this->touched as [$uid, $module]) {
            $this->replace($uid, $module, null);
        }
        foreach ($this->created as $uid) {
            Server::get(IUserManager::class)->get($uid)?->delete();
        }
        parent::tearDown();
    }

    /** Saves (or, with null, clears) one scope exactly as the writer's setResourceScope() stores it. */
    private function replace(string $uid, string $module, ?array $items): string {
        $revision = '';
        Server::get(PolicyWriter::class)->runInternal([], static fn () => null,
            function ($snapshot, array $current) use ($uid, $module, $items, &$revision): bool {
                $revision = PolicyWriter::next($current['revision']);
                Server::get(ResourceScopeMapper::class)->replace($uid, $module, $items, $revision);
                return true;
            });
        $this->touched[] = [$uid, $module];
        return $revision;
    }

    /** @return array<string, array{policyRev:string, items:list<string>}> */
    private function scopes(string $uid): array {
        return Server::get(ResourceScopeMapper::class)->forUser($uid);
    }

    private function account(): string {
        $uid = 'mcp-it-scope-' . bin2hex(random_bytes(4));
        $this->assertNotNull(Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(16))), 'the account was created');
        $this->created[] = $uid;
        return $uid;
    }

    private function revision(): string {
        return Server::get(PolicyStateMapper::class)->state()['revision'];
    }

    public function testTheRealDeletionRemovesTheScopeTheAccountHadAndLeavesOtherAccountsAlone(): void {
        $uid = $this->account();
        $this->replace($uid, 'files', ['100', '101']);
        $this->replace($uid, 'calendar', []);
        $this->replace(Fixtures::ALICE, 'files', ['200']);
        $before = $this->revision();

        $this->assertTrue(Server::get(IUserManager::class)->get($uid)->delete());

        $this->assertSame([], $this->scopes($uid), 'the scope went with the account, on time');
        $this->assertSame(['200'], $this->scopes(Fixtures::ALICE)['files']['items']);
        $this->assertNotSame($before, $this->revision(), 'removing it advanced the revision');
        $this->created = array_values(array_diff($this->created, [$uid]));
    }

    public function testAScopeSavedAfterTheDeletionBeganIsKeptWhileTheOlderOneIsRemoved(): void {
        $uid = $this->account();
        $this->replace($uid, 'files', ['100']);
        $saved = '';
        // Runs before the app's listener (higher priority): the account is already deleted, a new incarnation is
        // configured, and only then does the app's UserDeletedEvent handler remove what R_b qualifies.
        $listener = function () use ($uid, &$saved): void {
            $saved = $this->replace($uid, 'calendar', ['300']);
        };
        Server::get(IEventDispatcher::class)->addListener(UserDeletedEvent::class, $listener, 1000);
        $this->listeners[] = [UserDeletedEvent::class, $listener];

        $this->assertTrue(Server::get(IUserManager::class)->get($uid)->delete());

        $this->assertNotSame('', $saved, 'the interleaved save ran');
        $remaining = $this->scopes($uid);
        $this->assertSame(['calendar'], array_keys($remaining), 'only the scope saved after R_b survives');
        $this->assertSame($saved, $remaining['calendar']['policyRev']);
        $this->created = array_values(array_diff($this->created, [$uid]));
    }

    public function testTheJobRemovesTheScopeOfAnAccountThatIsGoneAndKeepsThatOfOneThatExists(): void {
        $gone = 'mcp-it-scope-gone-' . bin2hex(random_bytes(4));
        $this->replace($gone, 'tasks', ['400']);
        $this->replace(Fixtures::ALICE, 'files', ['200']);

        Server::get(ScopeCleanup::class)->reconcile();

        $this->assertSame([], $this->scopes($gone), 'an id no backend knows is proven gone');
        $this->assertSame(['200'], $this->scopes(Fixtures::ALICE)['files']['items'], 'an account that exists keeps its scope');
    }

    public function testAnAccountRecreatedBeforeTheJobKeepsItsRestriction(): void {
        $uid = $this->account();
        $this->replace($uid, 'files', ['100']);
        // The deletion event never reached the app (deferred or lost); the same id exists again when the job runs.
        Server::get(ScopeCleanup::class)->reconcile();

        $this->assertSame(['100'], $this->scopes($uid)['files']['items'], 'never lifted while the account exists');
    }
}
