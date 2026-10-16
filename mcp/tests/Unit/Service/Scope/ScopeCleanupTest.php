<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Service\Scope;

use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Service\Policy\DecimalCounter;
use OCA\Mcp\Service\Policy\PolicyUnavailable;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Service\Policy\ScopeCleanup;
use OCA\Mcp\Tests\Unit\InMemoryAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * S12 (contracts B.3, F3) on the real writer, state, events and scope tables in SQLite: a qualified removal on time
 * (policy_rev <= R_b), a scope saved later kept, a job that removes only an account proven gone and only while the
 * revision is the one read, and a recreated account that keeps its restriction. Every account read runs outside the
 * mutex, and what cannot be proven keeps the scope.
 */
final class ScopeCleanupTest extends TestCase {
    private ScopeFixture $fx;
    private PolicyWriter $writer;
    private ScopeCleanup $cleanup;
    private InMemoryAppConfig $apps;
    /** @var list<string> accounts that exist */
    private array $existing = [];
    /** @var array<string, \Throwable> accounts whose read fails */
    private array $failing = [];
    /** @var bool whether the object lookup says missing while the backend's existence answer says present */
    private bool $backendSaysPresent = false;
    /** @var (\Closure(string): void)|null runs inside every account read */
    private ?\Closure $readHook = null;
    /** @var list<string> */
    private array $reads = [];
    /** @var list<array{string, string, array}> */
    private array $logged = [];

    protected function setUp(): void {
        $this->fx = new ScopeFixture($this);
        $this->writer = $this->fx->writer();
        $this->apps = new InMemoryAppConfig();
        $this->cleanup = $this->cleanup();
    }

    private function cleanup(): ScopeCleanup {
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid) {
            $this->read($uid);
            return in_array($uid, $this->existing, true) ? $this->fx->user($uid) : null;
        });
        $users->method('userExists')->willReturnCallback(function (string $uid): bool {
            $this->read($uid);
            return in_array($uid, $this->existing, true) || $this->backendSaysPresent;
        });
        $logger = new class($this->logged) extends AbstractLogger {
            /** @param list<array{string, string, array}> $log */
            public function __construct(private array &$log) {
            }

            public function log($level, string|\Stringable $message, array $context = []): void {
                $this->log[] = [(string)$level, (string)$message, $context];
            }
        };
        return new ScopeCleanup($this->writer, $this->fx->scopes, $users, $this->apps->worker($this), $logger);
    }

    private function read(string $uid): void {
        $this->reads[] = $uid;
        $this->assertFalse($this->fx->db->inTransaction(), 'the account is never read under the mutex or inside a transaction');
        if (isset($this->failing[$uid])) {
            throw $this->failing[$uid];
        }
        if ($this->readHook !== null) {
            ($this->readHook)($uid);
        }
    }

    /** One saved scope, as a committed save writes it: policy_rev is the revision the commit produces. */
    private function save(string $uid, string $module, ?array $ids): string {
        $revision = '';
        $this->writer->runInternal([], static fn () => null, function ($snapshot, array $current) use ($uid, $module, $ids, &$revision): bool {
            $revision = PolicyWriter::next($current['revision']);
            $this->fx->scopes->replace($uid, $module, $ids, $revision);
            return true;
        });
        return $revision;
    }

    /** @return array<string, array{policyRev:string, items:list<string>}> */
    private function scopes(string $uid): array {
        return $this->fx->scopes->forUser($uid);
    }

    private function revision(): string {
        return $this->fx->revision();
    }

    /** @return list<string> messages logged at a level */
    private function logs(string $level): array {
        return array_values(array_map(static fn (array $entry): string => $entry[1], array_filter($this->logged, static fn (array $entry): bool => $entry[0] === $level)));
    }

    // --- on time: UserDeletedEvent with R_b -----------------------------------------------------------------------

    public function testTheScopesSavedUpToRbAreRemovedWithTheirItemsAndTheRevisionAdvances(): void {
        $this->save('alice', 'files', ['10', '11']);
        $this->save('alice', 'calendar', []);
        $this->save('bob', 'files', ['20']);
        $rb = $this->revision();
        $before = $this->revision();

        $this->assertSame(['calendar', 'files'], $this->cleanup->deleted('alice', $rb));

        $this->assertSame([], $this->scopes('alice'));
        $this->assertSame(['20'], $this->scopes('bob')['files']['items'], 'another account is untouched');
        $this->assertSame(0, (int)$this->fx->database->dbal->fetchOne("SELECT COUNT(*) FROM mcp_scope_items WHERE resource_id IN ('10', '11')"));
        $this->assertNotSame($before, $this->revision(), 'removing a scope is a policy change and advances the revision');
        $this->assertContains('MCP resource scope removed with its deleted account', $this->logs('info'));
    }

    public function testAScopeSavedAtExactlyRbIsRemovedAndOneSavedAfterItIsNever(): void {
        $this->save('alice', 'files', ['10']);
        $rb = $this->revision(); // policy_rev of the files scope equals R_b
        $this->assertSame($rb, $this->scopes('alice')['files']['policyRev']);
        // The account was recreated and configured again after the deletion started.
        $later = $this->save('alice', 'calendar', ['30']);
        $this->assertSame(1, DecimalCounter::compare($later, $rb));

        $this->assertSame(['files'], $this->cleanup->deleted('alice', $rb));

        $this->assertSame(['calendar'], array_keys($this->scopes('alice')));
        $this->assertSame(['30'], $this->scopes('alice')['calendar']['items']);
        $this->assertSame($later, $this->scopes('alice')['calendar']['policyRev']);
    }

    public function testAScopeReplacedAfterRbIsNotRemovedEvenIfAnOlderOneExistedForTheModule(): void {
        $this->save('alice', 'files', ['10']);
        $rb = $this->revision();
        $this->save('alice', 'files', ['10', '99']); // the same module saved again later: a new policy_rev

        $this->assertSame([], $this->cleanup->deleted('alice', $rb));
        $this->assertSame(['10', '99'], $this->scopes('alice')['files']['items']);
    }

    public function testNothingToRemoveCommitsNothing(): void {
        $this->save('bob', 'files', ['20']);
        $before = $this->fx->snapshot();
        $this->assertSame([], $this->cleanup->deleted('alice', $this->revision()));
        $this->assertSame($before, $this->fx->snapshot(), 'no revision, no event');
    }

    public function testRevisionsAreComparedAsDecimalsAndInvalidInputIsRefused(): void {
        $this->save('alice', 'files', ['10']);
        foreach (['', '01', '-1', '1.5', 'abc', '99999999999999999999999'] as $bad) {
            try {
                $this->cleanup->deleted('alice', $bad);
                $this->fail("accepted R_b '$bad'");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->cleanup->deleted("bad\0uid", '1');
    }

    public function testAnInvalidStoredRowKeepsEveryScopeOfTheUserAndFailsClosed(): void {
        $this->save('alice', 'files', ['10']);
        $this->save('alice', 'calendar', ['30']);
        // An item whose digest does not match its id: the removal verifies every row of the user before deleting any.
        $this->fx->database->dbal->insert('mcp_scope_items', ['user_key' => \OCA\Mcp\Db\IdentityKey::user('alice'), 'module' => 'tasks',
            'resource_key' => str_repeat('0', 64), 'resource_id' => '5']);
        $before = $this->fx->snapshot();

        try {
            $this->cleanup->deleted('alice', $this->revision());
            $this->fail('removed despite an invalid row');
        } catch (PolicyUnavailable) {
        }
        $this->assertSame($before, $this->fx->snapshot());
        $this->assertSame(['calendar', 'files'], array_keys($this->fx->database->dbal->fetchAllKeyValue('SELECT module, user_id FROM mcp_scope_modes ORDER BY module')));
    }

    // --- job ------------------------------------------------------------------------------------------------------

    public function testTheJobKeepsTheScopeOfAnAccountThatExistsWithoutTakingTheMutex(): void {
        $this->existing = ['alice'];
        $this->save('alice', 'files', ['10']);
        $before = $this->fx->snapshot();

        $this->assertSame(0, $this->cleanup->reconcile());

        $this->assertSame($before, $this->fx->snapshot());
        $this->assertSame(['10'], $this->scopes('alice')['files']['items']);
        $this->assertSame(['alice'], $this->reads, 'one fresh read of the account, and no second proof under a command');
    }

    public function testTheJobRemovesTheScopeOfAnAccountProvenGoneAndAdvancesTheRevision(): void {
        $this->existing = ['bob'];
        $this->save('alice', 'files', ['10']);
        $this->save('alice', 'tasks', ['40']);
        $this->save('bob', 'files', ['20']);
        $before = $this->revision();

        $this->assertSame(1, $this->cleanup->reconcile());

        $this->assertSame([], $this->scopes('alice'));
        $this->assertSame(['20'], $this->scopes('bob')['files']['items']);
        $this->assertNotSame($before, $this->revision());
        $this->assertContains('MCP resource scope removed with its deleted account', $this->logs('info'));
    }

    public function testAnAccountRecreatedBeforeTheJobKeepsItsInheritedRestrictionAndNeverBecomesUnrestricted(): void {
        $this->save('alice', 'files', ['10', '11']);
        // The deletion was deferred, the account was recreated under the same id, and the job runs now.
        $this->existing = ['alice'];

        $this->assertSame(0, $this->cleanup->reconcile());

        $this->assertSame(['10', '11'], $this->scopes('alice')['files']['items'], 'still restricted to the old items');
        $this->assertNotNull($this->fx->scopes->forModule('alice', 'files'));
    }

    public function testAnAccountRecreatedAndReconfiguredWhileTheJobProvesItGoneReplaysTheAttemptAndKeepsEverything(): void {
        $this->save('alice', 'files', ['10']);
        $revision = $this->revision();
        // The pre-check reads the account gone (reads 1 and 2: object, backend); during the proof of the command (read 3)
        // the account comes back and an administrator saves a new scope, which moves the revision under the command.
        $reads = 0;
        $this->readHook = function () use (&$reads): void {
            if (++$reads === 3) {
                $this->existing = ['alice'];
                $this->save('alice', 'calendar', ['30']);
            }
        };
        $this->assertSame(0, $this->cleanup->reconcile());

        $this->assertSame(['calendar', 'files'], array_keys($this->scopes('alice')));
        $this->assertSame(1, DecimalCounter::compare($this->scopes('alice')['calendar']['policyRev'], $revision));
    }

    public function testARevisionChangedBetweenTheProofAndTheMutexReplaysTheProofAndThenRemoves(): void {
        $this->save('alice', 'files', ['10']);
        $proofs = 0;
        $hook = function () use (&$proofs): void {
            // Read 1 is the pre-check; read 2 is the proof of the command, which moves the revision once.
            if (++$proofs === 2) {
                $this->writer->setGlobalEnabled(true);
            }
        };
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid) use ($hook) {
            $hook();
            return null;
        });
        $users->method('userExists')->willReturn(false);
        $cleanup = new ScopeCleanup($this->writer, $this->fx->scopes, $users, $this->apps->worker($this), new \Psr\Log\NullLogger());

        $this->assertSame(1, $cleanup->reconcile());

        $this->assertSame([], $this->scopes('alice'));
        $this->assertSame(3, $proofs, 'the proof was read again after the revision moved');
    }

    public function testWhatCannotBeProvenKeepsTheScope(): void {
        $this->save('alice', 'files', ['10']);
        $this->failing['alice'] = new \RuntimeException('directory down');
        $before = $this->fx->snapshot();
        $this->assertSame(0, $this->cleanup->reconcile());
        $this->assertSame($before, $this->fx->snapshot());
        $this->assertNotEmpty($this->logs('warning'));

        unset($this->failing['alice']);
        // The object lookup says missing but the backend still answers that the account exists.
        $this->backendSaysPresent = true;
        $this->assertSame(0, $this->cleanup->reconcile());
        $this->assertSame($before, $this->fx->snapshot());
        $this->assertSame(['10'], $this->scopes('alice')['files']['items']);
    }

    public function testAnInvalidRowOfOneUserBlocksOnlyThatUserAndIsDiagnosed(): void {
        $this->save('alice', 'files', ['10']);
        $this->save('bob', 'files', ['20']);
        $this->fx->database->dbal->insert('mcp_scope_items', ['user_key' => \OCA\Mcp\Db\IdentityKey::user('alice'), 'module' => 'tasks',
            'resource_key' => str_repeat('0', 64), 'resource_id' => '5']);

        $this->assertSame(1, $this->cleanup->reconcile(), 'bob, whose rows are valid, is still cleaned');

        $this->assertSame(['10'], $this->scopes_unverified('alice'));
        $this->assertSame([], $this->scopes('bob'));
        $diagnostic = array_values(array_filter($this->logged, static fn (array $entry): bool => $entry[1] === 'MCP resource scope cleanup pass'));
        $this->assertCount(1, $diagnostic);
        $this->assertSame(2, $diagnostic[0][2]['users']);
        $this->assertSame(1, $diagnostic[0][2]['blocked_or_failed']);
        $this->assertSame(1, $diagnostic[0][2]['cleaned']);
    }

    public function testAModeWhoseStoredOwnerDoesNotMatchItsKeyIsNeverJudgedByTheDirectory(): void {
        $this->save('alice', 'files', ['10']);
        // The mode of alice's key claims another owner: the id cannot be trusted, so no account is read for it.
        $this->fx->database->dbal->executeStatement('UPDATE mcp_scope_modes SET user_id = ?', ['mallory']);
        $before = $this->fx->snapshot();

        $this->assertSame(0, $this->cleanup->reconcile());

        $this->assertSame([], $this->reads, 'no directory read for an owner that fails its digest');
        $this->assertSame($before, $this->fx->snapshot());
        $this->assertNotEmpty($this->logs('warning'));
    }

    public function testOrphanItemsAreCountedInTheDiagnosticAndLeftInPlace(): void {
        $this->existing = ['alice'];
        $this->save('alice', 'files', ['10']);
        $this->fx->database->dbal->insert('mcp_scope_items', ['user_key' => \OCA\Mcp\Db\IdentityKey::user('carol'), 'module' => 'files',
            'resource_key' => \OCA\Mcp\Db\IdentityKey::resource('files', '7'), 'resource_id' => '7']);

        $this->cleanup->reconcile();

        $diagnostic = array_values(array_filter($this->logged, static fn (array $entry): bool => $entry[1] === 'MCP resource scope cleanup pass'));
        $this->assertSame(1, $diagnostic[0][2]['orphan_item_users']);
        $this->assertSame(1, (int)$this->fx->database->dbal->fetchOne("SELECT COUNT(*) FROM mcp_scope_items WHERE resource_id = '7'"));
    }

    public function testThePageCursorMovesPastUsersThatKeepFailingAndWraps(): void {
        foreach (['alice', 'bob', 'carol'] as $uid) {
            $this->save($uid, 'files', ['10']);
        }
        $this->existing = ['bob', 'carol'];
        $keys = [];
        foreach (['alice', 'bob', 'carol'] as $uid) {
            $keys[\OCA\Mcp\Db\IdentityKey::user($uid)] = $uid;
        }
        ksort($keys);
        $order = array_values($keys);

        // Page size 1: each run looks at one user; the one that is gone is cleaned when its turn comes.
        $cleaned = 0;
        for ($run = 0; $run < 4; ++$run) {
            $cleaned += $this->cleanup->reconcile(1);
        }
        $this->assertSame(1, $cleaned);
        $this->assertSame([], $this->scopes('alice'));
        $this->assertNotSame([], $this->scopes('bob'));
        $this->assertNotSame([], $this->scopes('carol'));
        $this->assertEqualsCanonicalizing(['alice', 'bob', 'carol'], array_unique($this->reads), 'every user got its turn (order ' . implode(',', $order) . ')');
    }

    public function testNoDirectoryReadHappensUnderTheMutexOrInsideAnyTransaction(): void {
        $this->save('alice', 'files', ['10']);
        $this->save('bob', 'files', ['20']);
        $this->existing = ['bob'];
        $this->cleanup->reconcile();
        // read() asserts, on every account read, that no transaction is open; the removal itself ran in one.
        $this->assertNotEmpty($this->reads);
        $this->assertSame([], $this->scopes('alice'));
    }

    // --- the contract's negative promises --------------------------------------------------------------------------

    public function testThereIsNoUnqualifiedDeleteByUserAndNoAuthGenerationInTheScopeTables(): void {
        foreach ((new \ReflectionClass(ResourceScopeMapper::class))->getMethods() as $method) {
            $this->assertDoesNotMatchRegularExpression('/^delete(ForUser|User|All)/i', $method->getName(), 'no unqualified delete in the scope mapper');
        }
        foreach ([ResourceScopeMapper::class, ScopeCleanup::class] as $class) {
            $source = (string)file_get_contents((string)(new \ReflectionClass($class))->getFileName());
            $this->assertStringNotContainsString('auth_gen', $source);
            $this->assertStringNotContainsString('deleteForUser', $source);
        }
    }

    /** @return list<string> the items of the user's files scope, read without the verification that would refuse the user */
    private function scopes_unverified(string $uid): array {
        $rows = $this->fx->database->dbal->fetchFirstColumn("SELECT i.resource_id FROM mcp_scope_modes m JOIN mcp_scope_items i ON i.user_key = m.user_key AND i.module = m.module WHERE m.user_key = ? AND m.module = 'files'",
            [\OCA\Mcp\Db\IdentityKey::user($uid)]);
        return array_map('strval', $rows);
    }
}
