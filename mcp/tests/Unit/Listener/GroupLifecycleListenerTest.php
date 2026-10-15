<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Listener;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\Listener\GroupLifecycleListener;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Service\Policy\CauseProcessor;
use OCA\Mcp\Service\Policy\GroupLifecycle;
use OCA\Mcp\Service\Policy\GroupSourceClassifier;
use OCA\Mcp\Service\Policy\PolicyOperationContext;
use OCA\Mcp\Service\Policy\PolicyResolver;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\Service\Policy\GroupLifecycleDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Group\Events\BeforeGroupCreatedEvent;
use OCP\Group\Events\BeforeGroupDeletedEvent;
use OCP\Group\Events\GroupCreatedEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The group events of contracts §3.3.2/§3.3.5 on SQLite, through the production listener, lifecycle service, writer
 * and mappers; the cause processor is a double that records what it is asked, and the directory and the resolved
 * eligibility are doubles. T6 (unit part: deletion with holders, late events, creation by R_c, creation during a
 * deletion), T19/F5 (no query inside a caller's transaction) and the rule that no pre-event takes the mutex.
 */
final class GroupLifecycleListenerTest extends TestCase {
    use GroupLifecycleDatabase;

    private InMemoryOAuthStore $store;
    /** @var list<array{string, string, string}> after() calls */
    private array $afters = [];
    /** @var list<string> deferGroup() calls */
    private array $deferred = [];
    /** @var list<string> events during which flush() was called */
    private array $flushes = [];
    private string $current = '';
    /** @var list<string> members of every group of the test */
    private array $members = ['alice', 'bob'];
    /** @var \Closure(string, list<string>): ?bool resolved eligibility of a user without the given groups */
    private \Closure $eligibility;
    /** @var list<string> uids the listener loaded an account for */
    private array $accounts = [];
    /** @var list<string> uids the listener resolved */
    private array $resolved = [];
    /** @var list<array{string, string}> warnings logged: message, target */
    private array $warnings = [];

    protected function setUp(): void {
        $this->lifecycleDatabase();
        $this->store = new InMemoryOAuthStore();
        // alice is eligible only through staff; bob also individually; carol holds a credential but is no member.
        $this->eligibility = static fn (string $uid, array $without): ?bool => $uid === 'bob' || !in_array('staff', $without, true);
        foreach (['alice', 'bob', 'carol'] as $uid) {
            $this->store->insertToken(['user_id' => $uid, 'auth_gen' => '1'], 0);
        }
    }

    private function causes(): CauseProcessor {
        $causes = $this->createMock(CauseProcessor::class);
        $causes->method('after')->willReturnCallback(function (string $uid, string $key, string $gen): void {
            $this->afters[] = [$uid, $key, $gen];
        });
        $causes->method('deferGroup')->willReturnCallback(function (string $gid): void {
            $this->deferred[] = $gid;
        });
        $causes->method('flush')->willReturnCallback(function (): void {
            $this->flushes[] = $this->current;
        });
        return $causes;
    }

    private function listener(?IDBConnection $db = null, ?GroupLifeMapper $life = null, ?CauseProcessor $causes = null,
            ?GroupLifecycle $lifecycle = null, ?OAuthStore $store = null, ?RecheckMapper $rechecks = null,
            ?PolicyStateMapper $state = null, ?GroupGrantsMapper $grants = null): GroupLifecycleListener {
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): IUser {
            $this->accounts[] = $uid;
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            return $user;
        });
        $classifier = $this->createMock(GroupSourceClassifier::class);
        $classifier->method('trusted')->willReturn(true);
        $classifier->method('members')->willReturnCallback(fn (): array => array_combine(array_map(static fn (string $uid): string => hash('sha256', $uid), $this->members), $this->members));
        $resolver = $this->createMock(PolicyResolver::class);
        $resolver->method('begin')->willReturnCallback(function (string $uid, array $added = [], array $removed = [], bool $observe = true): PolicyOperationContext {
            $this->assertFalse($observe, 'a group handler never persists an observation');
            $this->resolved[] = $uid;
            $eligible = ($this->eligibility)($uid, $removed);
            $raw = ['eligible' => $eligible === null ? ['present' => false] : ['present' => true, 'value' => $eligible ? '1' : '0']];
            $candidates = $eligible === null ? [['groupId' => 'x', 'grants' => ['_system' => ['eligible']], 'trusted' => false, 'state' => 'active']] : [];
            return new PolicyOperationContext('0', $this->createMock(PolicyWriter::class), $raw, $candidates, true, true, [], true, true,
                \OCA\Mcp\Service\Scope\ScopeGuard::read($this->createMock(\OCA\Mcp\Db\ResourceScopeMapper::class), null));
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1_760_000_000);
        $logger = new class($this->warnings) extends \Psr\Log\AbstractLogger {
            public function __construct(private array &$warnings) {}
            public function log($level, string|\Stringable $message, array $context = []): void {
                $this->warnings[] = [(string)$message, (string)($context['target'] ?? '')];
            }
        };
        return new GroupLifecycleListener($lifecycle ?? $this->lifecycle, $causes ?? $this->causes(), $life ?? $this->life,
            $grants ?? $this->grants, $rechecks ?? $this->rechecks, $state ?? $this->state, $store ?? $this->store,
            new AuthUsersMapper($db ?? $this->db), $classifier, $resolver, $users, $db ?? $this->db, $time, $logger);
    }

    private function group(string $gid = 'staff'): IGroup {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn($gid);
        $group->method('inGroup')->willReturnCallback(fn (IUser $user): bool => in_array($user->getUID(), $this->members, true));
        return $group;
    }

    private function handle(GroupLifecycleListener $listener, object $event): void {
        $this->current = $event::class;
        $listener->handle($event);
    }

    /** @return list<string> uid of every pending cause of the group */
    private function causedUsers(string $gid): array {
        $users = [];
        foreach (['alice', 'bob', 'carol'] as $uid) {
            foreach ($this->rechecks->causes($uid) as $cause) {
                if ($cause['causeKey'] === IdentityKey::group($gid)) {
                    $users[] = $uid;
                }
            }
        }
        return $users;
    }

    /**
     * T6: deleting a group that grants eligibility. Its Before records causes only for the credential holders in G who
     * would not be eligible without it, then pending_delete, without the mutex; its After purges under the mutex and
     * runs the protocol for exactly those causes, with their gens, after the purge.
     */
    public function testADeletionRecordsTheHoldersCausesThenPendingDeleteAndItsAfterPurges(): void {
        $this->policy('staff');
        [$revision, $lock] = [$this->revision(), $this->lockSeq()];
        $listener = $this->listener();

        $this->handle($listener, new BeforeGroupDeletedEvent($this->group()));
        $this->assertSame(['alice'], $this->causedUsers('staff'), 'bob keeps eligibility; carol is no member');
        $this->assertSame('pending_delete', $this->life->get('staff')['state']);
        $this->assertSame([$revision, $lock], [$this->revision(), $this->lockSeq()], 'a pre-event takes no mutex');
        $this->assertSame([], $this->flushes, 'a pre-event flushes nothing');
        $gen = $this->rechecks->causes('alice')[0]['gen'];

        $this->handle($listener, new GroupDeletedEvent($this->group()));
        $this->assertNull($this->life->get('staff'));
        $this->assertSame([], $this->rows('staff'));
        $this->assertSame(PolicyWriter::next($revision), $this->revision());
        $this->assertSame([['alice', IdentityKey::group('staff'), $gen]], $this->afters);
        $this->assertSame([GroupDeletedEvent::class], $this->flushes);
        // A duplicate After finds nothing captured any more: it suspends nothing that is gone and purges nothing.
        $this->handle($listener, new GroupDeletedEvent($this->group()));
        $this->assertSame(PolicyWriter::next($revision), $this->revision());
    }

    /** T6: without holders (or with a group granting ordinary operations only) pending_delete is still written. */
    public function testADeletionWithoutHoldersStillMarksPendingDelete(): void {
        $this->policy('team', grants: [['notes', 'edit']]);
        $this->members = ['alice'];
        $listener = $this->listener();
        $this->handle($listener, new BeforeGroupDeletedEvent($this->group('team')));
        $this->assertSame([], $this->causedUsers('team'));
        $this->assertSame('pending_delete', $this->life->get('team')['state']);
    }

    /** A group the app holds no policy for: no read beyond its lifecycle, no write, no mutex. */
    public function testAGroupWithoutLifecycleIsIrrelevant(): void {
        $lock = $this->lockSeq();
        $listener = $this->listener();
        foreach ([new BeforeGroupDeletedEvent($this->group('plain')), new GroupDeletedEvent($this->group('plain')),
            new BeforeGroupCreatedEvent('plain'), new GroupCreatedEvent($this->group('plain'))] as $event) {
            $this->handle($listener, $event);
        }
        $this->assertSame($lock, $this->lockSeq());
        $this->assertSame([], $this->causedUsers('plain'));
        $this->assertSame([], $this->afters);
    }

    /** A Before that could not write pending_delete never leads to a purge on an unknown gen: needs_confirm. */
    public function testAFailedPendingWriteSuspendsThePolicyInsteadOfPurgingIt(): void {
        $this->policy('staff');
        $life = new class($this->db) extends GroupLifeMapper {
            public function markPendingDelete(string $gid, string $expectedGen, string $gen): bool {
                throw new \RuntimeException('update failed');
            }
        };
        $listener = $this->listener(life: $life);
        $this->handle($listener, new BeforeGroupDeletedEvent($this->group()));
        $this->handle($listener, new GroupDeletedEvent($this->group()));
        $this->assertSame('needs_confirm', $this->life->get('staff')['state']);
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'));
    }

    /**
     * Re-review R1: a Before whose transition keeps losing (the lifecycle moves after every read) gives up after
     * MARK_ROUNDS rounds and records a cause for every live holder in G, the relevance filter dropped (Q1); its After
     * suspends G and processes them all. One gen per holder across the rounds.
     */
    public function testABeforeThatKeepsLosingItsTransitionRecordsACauseForEveryLiveHolderInTheGroup(): void {
        $this->policy('staff');
        $rounds = 0;
        $life = new class($this->db, $rounds) extends GroupLifeMapper {
            public function __construct(IDBConnection $db, private int &$rounds) { parent::__construct($db); }
            public function markPendingDelete(string $gid, string $expectedGen, string $gen): bool {
                ++$this->rounds;
                return false;
            }
        };
        $listener = $this->listener(life: $life);
        $this->handle($listener, new BeforeGroupDeletedEvent($this->group()));
        $this->assertSame(GroupLifecycleListener::MARK_ROUNDS, $rounds);
        $this->assertSame(['alice', 'bob'], $this->causedUsers('staff'), 'bob too: nothing judged him; carol is no member');
        $gens = [$this->rechecks->causes('alice')[0]['gen'], $this->rechecks->causes('bob')[0]['gen']];
        $this->handle($listener, new GroupDeletedEvent($this->group()));
        $this->assertSame('needs_confirm', $this->life->get('staff')['state']);
        $this->assertSame([['alice', IdentityKey::group('staff'), $gens[0]], ['bob', IdentityKey::group('staff'), $gens[1]]], $this->afters);
    }

    /** T6: a policy saved between the Before and the After of a deletion has a new gen; the After purges nothing. */
    public function testAPolicySavedDuringTheDeletionIsNeverPurgedByItsAfter(): void {
        $this->policy('staff');
        $listener = $this->listener();
        $this->handle($listener, new BeforeGroupDeletedEvent($this->group()));
        $saved = $this->save('staff');
        $this->handle($listener, new GroupDeletedEvent($this->group()));
        $this->assertSame($saved, $this->life->get('staff')['gen']);
        $this->assertSame('active', $this->life->get('staff')['state']);
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'));
    }

    /** T6: recreating the id purges the residual policy; one saved after the creation's Before is kept. */
    public function testACreationPurgesAResidualPolicyAndKeepsOneSavedAfterItsBefore(): void {
        $this->policy('staff', policyRev: $this->revision());
        $listener = $this->listener();
        $this->handle($listener, new BeforeGroupCreatedEvent('staff'));
        $this->handle($listener, new GroupCreatedEvent($this->group()));
        $this->assertNull($this->life->get('staff'));
        $this->assertSame([], $this->rows('staff'));
        $this->assertSame([GroupCreatedEvent::class], $this->flushes, 'only the post-event flushes');

        $this->policy('team', policyRev: $this->revision());
        $this->handle($listener, new BeforeGroupCreatedEvent('team'));
        $this->save('team');
        $this->handle($listener, new GroupCreatedEvent($this->group('team')));
        $this->assertSame('active', $this->life->get('team')['state']);
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('team'));
    }

    /** T6: a backend that names the group itself (another id than announced), or no R_c at all: needs_confirm. */
    public function testACreationWithoutAUsableRevisionSuspendsThePolicy(): void {
        $this->policy('staff');
        $this->policy('team');
        $listener = $this->listener();
        $this->handle($listener, new BeforeGroupCreatedEvent('requested-name'));
        $this->handle($listener, new GroupCreatedEvent($this->group('staff')));
        $this->assertSame('needs_confirm', $this->life->get('staff')['state']);
        $this->handle($listener, new GroupCreatedEvent($this->group('team')));
        $this->assertSame('needs_confirm', $this->life->get('team')['state']);
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'));
    }

    /** Creating G2 and adding members while G1 is being deleted is never refused, and each signal acts on its own id. */
    public function testCreatingAnotherGroupDuringADeletionIsNeverRefused(): void {
        $this->policy('staff');
        $listener = $this->listener();
        $this->handle($listener, new BeforeGroupDeletedEvent($this->group()));
        $this->handle($listener, new BeforeGroupCreatedEvent('second'));
        $this->handle($listener, new GroupCreatedEvent($this->group('second')));
        $this->handle($listener, new GroupDeletedEvent($this->group()));
        $this->assertNull($this->life->get('staff'));
        $this->assertNull($this->life->get('second'));
    }

    /** T19/F5: inside a caller's transaction no handler runs a single query; deletion and creation are deferred. */
    public function testInsideACallersTransactionNoHandlerRunsAnyQuery(): void {
        $db = $this->createMock(IDBConnection::class);
        $db->method('inTransaction')->willReturn(true);
        foreach (['getQueryBuilder', 'executeQuery', 'executeStatement', 'prepare', 'beginTransaction', 'commit', 'rollBack'] as $method) {
            $db->expects($this->never())->method($method);
        }
        $silent = [];
        foreach ([GroupLifeMapper::class, RecheckMapper::class, PolicyStateMapper::class, OAuthStore::class, GroupLifecycle::class] as $class) {
            $silent[$class] = $this->createMock($class);
            $silent[$class]->expects($this->never())->method($this->anything());
        }
        $listener = $this->listener($db, $silent[GroupLifeMapper::class], null, $silent[GroupLifecycle::class], $silent[OAuthStore::class],
            $silent[RecheckMapper::class], $silent[PolicyStateMapper::class], new GroupGrantsMapper($db));
        foreach ([new BeforeGroupDeletedEvent($this->group()), new GroupDeletedEvent($this->group()),
            new BeforeGroupCreatedEvent('team'), new GroupCreatedEvent($this->group('team'))] as $event) {
            $this->handle($listener, $event);
        }
        $this->assertSame(['staff', 'staff', 'team'], $this->deferred);
        $this->assertSame([], $this->flushes);
    }

    /** A creation whose Before came inside the transaction and its event after it: no R_c, so needs_confirm. */
    public function testACreationWhoseBeforeWasInsideATransactionGetsNoRevision(): void {
        $this->policy('staff');
        $this->database->dbal->beginTransaction();
        $listener = $this->listener();
        $this->handle($listener, new BeforeGroupCreatedEvent('staff'));
        $this->database->dbal->commit();
        $this->handle($listener, new GroupCreatedEvent($this->group()));
        $this->assertSame('needs_confirm', $this->life->get('staff')['state']);
    }

    /** T5/T8: an exception anywhere in a handler never reaches the core. */
    public function testAnExceptionInEachHandlerIsContained(): void {
        $this->policy('staff');
        $causes = $this->createMock(CauseProcessor::class);
        $causes->method('flush')->willThrowException(new \RuntimeException('flush down'));
        $causes->method('after')->willThrowException(new \RuntimeException('writer down'));
        $rechecks = $this->createMock(RecheckMapper::class);
        $rechecks->method('record')->willThrowException(new \RuntimeException('insert failed'));
        $state = $this->createMock(PolicyStateMapper::class);
        $state->method('state')->willThrowException(new \RuntimeException('read failed'));
        $lifecycle = $this->createMock(GroupLifecycle::class);
        $lifecycle->method('deleted')->willThrowException(new \RuntimeException('purge failed'));
        $lifecycle->method('created')->willThrowException(new \RuntimeException('purge failed'));
        foreach ([$this->listener(causes: $causes, rechecks: $rechecks, state: $state), $this->listener(lifecycle: $lifecycle)] as $listener) {
            foreach ([new BeforeGroupDeletedEvent($this->group()), new GroupDeletedEvent($this->group()),
                new BeforeGroupCreatedEvent('staff'), new GroupCreatedEvent($this->group())] as $event) {
                $this->handle($listener, $event);
            }
        }
        $this->addToAssertionCount(1);
    }

    /** Review F1: a failure while listing the holders never stops pending_delete; it is logged. */
    public function testAFailedHolderListingStillMarksPendingDelete(): void {
        $this->policy('staff');
        $store = $this->createMock(OAuthStore::class);
        $store->method('liveHolders')->willThrowException(new \RuntimeException('tokens unreadable'));
        $listener = $this->listener(store: $store);
        $this->handle($listener, new BeforeGroupDeletedEvent($this->group()));
        $this->assertSame('pending_delete', $this->life->get('staff')['state']);
        $this->assertSame([], $this->causedUsers('staff'));
        $this->assertContains(['Credential holders of a deleted group not listed; their causes are left to observed use', 'staff'], $this->warnings);
        // The After still purges with the gen the Before wrote.
        $this->handle($listener, new GroupDeletedEvent($this->group()));
        $this->assertNull($this->life->get('staff'));
    }

    /**
     * Review F2: the Before resolves only the live holders who are members of G and not cut yet. Expired, old-epoch,
     * non-member and already cut holders cost no account load and no resolution.
     */
    public function testTheBeforeResolvesOnlyLiveUncutHoldersWhoAreMembers(): void {
        $this->policy('staff');
        $this->store->tokens = [];
        $this->members = ['alice', 'dave', 'erin', 'frank'];
        $this->store->insertToken(['user_id' => 'alice', 'auth_gen' => '2', 'refresh_expires' => 1_760_000_100, 'auth_global_epoch' => '0'], 0);
        $this->store->insertToken(['user_id' => 'carol', 'auth_gen' => '2', 'refresh_expires' => 1_760_000_100, 'auth_global_epoch' => '0'], 0);
        $this->store->insertToken(['user_id' => 'dave', 'auth_gen' => '2', 'refresh_expires' => 1_759_999_999, 'auth_global_epoch' => '0'], 0);
        $this->store->insertToken(['user_id' => 'erin', 'auth_gen' => '2', 'refresh_expires' => 1_760_000_100, 'auth_global_epoch' => '7'], 0);
        $this->store->insertCode(['user_id' => 'frank', 'auth_gen' => '3', 'expires_at' => 1_760_000_100, 'auth_global_epoch' => '0'], 0);
        $this->database->dbal->insert('mcp_auth_users', ['user_key' => IdentityKey::user('frank'), 'user_id' => 'frank',
            'issue_seq' => '3', 'revoked_below' => '3']);
        $this->eligibility = static fn (string $uid, array $without): ?bool => !in_array('staff', $without, true);

        $this->handle($this->listener(), new BeforeGroupDeletedEvent($this->group()));

        $this->assertSame(['alice'], $this->accounts);
        $this->assertSame(['alice', 'alice'], $this->resolved);
        $this->assertSame(['alice'], $this->causedUsers('staff'));
    }
}
