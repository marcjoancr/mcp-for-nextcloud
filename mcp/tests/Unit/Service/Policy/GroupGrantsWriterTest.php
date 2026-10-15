<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Service\Policy;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Listener\GroupLifecycleListener;
use OCA\Mcp\Service\Policy\CauseProcessor;
use OCA\Mcp\Service\Policy\GroupGrantsService;
use OCA\Mcp\Service\Policy\GroupSourceClassifier;
use OCA\Mcp\Service\Policy\GroupSourceUntrusted;
use OCA\Mcp\Service\Policy\PolicyConflict;
use OCA\Mcp\Service\Policy\PolicyEventPayload;
use OCA\Mcp\Service\Policy\PolicyOperationContext;
use OCA\Mcp\Service\Policy\PolicyResolver;
use OCA\Mcp\Service\Policy\PolicyUnavailable;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Service\Policy\ReceiptExpired;
use OCA\Mcp\Service\Policy\ReceiptNotFound;
use PHPUnit\Framework\TestCase;

/**
 * The group save of ticket 08 on SQLite, with the production writer and mappers (contracts §2.3, §3.3.5): preview and
 * save, the 422 of a group that cannot grant, the member hash, the lifecycle gen compared under the mutex, the holders
 * rule H0/H1 and "Confirm current policy". The external phase is a double that records what it was given and answers
 * the source predicate, the member list and who loses its last source; GroupGrantsServiceTest covers the real one.
 * Validation T5 (part F3), T6 (422 without write) and T16 (server part).
 */
final class GroupGrantsWriterTest extends TestCase {
    use GroupLifecycleDatabase;

    private bool $trusted = true;
    /** @var list<string> */
    private array $members = ['ana', 'bob'];
    /** @var list<string> holders the external phase decides lose their last source */
    private array $losing = [];
    /** @var list<\Closure(): void> run one per external phase, in order */
    private array $during = [];
    /** @var list<array> what each external phase was given */
    private array $admitted = [];
    /** @var list<\Closure(): void> run one per external phase of realAdmit(), after its decisions */
    private array $after = [];
    /** @var array<string, list<string>> uid => the groups it is in, for realAdmit() */
    private array $memberOf = [];
    private bool $injected = false;

    protected function setUp(): void {
        $this->lifecycleDatabase();
    }

    private function admit(): \Closure {
        return function (array $command): array {
            $this->assertFalse($this->db->inTransaction(), 'no directory read under the mutex');
            $this->admitted[] = $command;
            if ($this->during !== []) {
                (array_shift($this->during))();
            }
            return ['trusted' => $this->trusted, 'members' => $this->members,
                'cuts' => array_values(array_intersect($command['holders'], $this->losing))];
        };
    }

    /**
     * The production external phase (GroupGrantsService) over this database: each resolution is a real
     * PolicyOperationContext built from the stored grant rows and lifecycles of the holder's groups and its individual
     * eligibility flag; the source predicate is true and the members are $this->members.
     */
    private function realAdmit(): \Closure {
        $service = new GroupGrantsService($this->writer, $this->classifier(), $this->resolver(), $this->grants, $this->life);
        return function (array $command) use ($service): array {
            $this->assertFalse($this->db->inTransaction(), 'no directory read under the mutex');
            $this->admitted[] = $command;
            $admission = $service->admit($command);
            if ($this->after !== []) {
                (array_shift($this->after))();
            }
            return $admission;
        };
    }

    /** Members of every group are $this->members; every group can grant. */
    private function classifier(): GroupSourceClassifier {
        $classifier = $this->createMock(GroupSourceClassifier::class);
        $classifier->method('trusted')->willReturn(true);
        $classifier->method('members')->willReturnCallback(fn (): array => array_combine(array_map(static fn (string $uid): string => hash('sha256', $uid), $this->members), $this->members));
        return $classifier;
    }

    /** Resolutions as PolicyOperationContext over the stored rows and lifecycles of the user's groups ($this->memberOf). */
    private function resolver(): PolicyResolver {
        $resolver = $this->createMock(PolicyResolver::class);
        $resolver->method('begin')->willReturnCallback(function (string $uid, array $added, array $removed, bool $observe): PolicyOperationContext {
            $gids = array_values(array_diff($this->memberOf[$uid] ?? [], $removed));
            $lives = [];
            foreach ($this->life->forGroups($gids) as $row) {
                $lives[$row['groupId']] = $row;
            }
            $candidates = [];
            foreach ($this->grants->forGroups($gids) as $row) {
                if ($row['grants'] !== []) {
                    $candidates[] = $row + ['trusted' => true, 'state' => $lives[$row['groupId']]['state'] ?? null, 'gen' => $lives[$row['groupId']]['gen'] ?? null];
                }
            }
            $flag = $this->configData->user[$uid]['mcp']['eligible'] ?? null;
            $raw = $flag === null ? [] : ['eligible' => ['present' => true, 'value' => $flag]];
            return new PolicyOperationContext($this->revision(), $this->writer, $raw, $candidates, true, true, [], true, true);
        });
        return $resolver;
    }

    private function realSave(string $gid, array $grants): array {
        $preview = $this->writer->previewGroupGrants($this->revision(), 'root', $gid, $grants, $this->realAdmit());
        return [$preview, fn (): array => $this->writer->setGroupGrants($preview['revision'], 'root', $gid, $preview['grants'], $preview['previewId'], $this->realAdmit())];
    }

    /**
     * The production GroupLifecycleListener and CauseProcessor (with the real CauseClassifier over resolver()) on this
     * database, the lifecycle mapper being $life.
     *
     * @return array{GroupLifecycleListener, CauseProcessor}
     */
    private function listener(GroupLifeMapper $life): array {
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): \OCP\IUser {
            $user = $this->createMock(\OCP\IUser::class);
            $user->method('getUID')->willReturn($uid);
            $user->method('isEnabled')->willReturn(true);
            return $user;
        });
        $time = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $causes = new \OCA\Mcp\Service\Policy\CauseProcessor($this->writer, $this->rechecks,
            new \OCA\Mcp\Service\Policy\CauseClassifier($users, $this->resolver()), $this->createMock(\OCA\Mcp\Service\Policy\DeferredQueue::class),
            $this->db, new \Psr\Log\NullLogger(), $this->apps->worker($this), $this->lifecycle);
        $listener = new \OCA\Mcp\Listener\GroupLifecycleListener($this->lifecycle, $causes, $life, $this->grants, $this->rechecks,
            $this->state, new OAuthStore($this->db), new AuthUsersMapper($this->db), $this->classifier(), $this->resolver(), $users,
            $this->db, $time, new \Psr\Log\NullLogger());
        return [$listener, $causes];
    }

    /** A mapper that runs $between once, inside the first markPendingDelete, before the real conditional transition. */
    private function interleaved(\Closure $between): GroupLifeMapper {
        return new class($this->db, $between) extends GroupLifeMapper {
            public function __construct(\OCP\IDBConnection $db, private ?\Closure $between) { parent::__construct($db); }
            public function markPendingDelete(string $gid, string $expectedGen, string $gen): bool {
                if ($this->between !== null) {
                    [$between, $this->between] = [$this->between, null];
                    $between();
                }
                return parent::markPendingDelete($gid, $expectedGen, $gen);
            }
        };
    }

    private function preview(mixed $grants, string $gid = 'staff', string $actor = 'root'): array {
        return $this->writer->previewGroupGrants($this->revision(), $actor, $gid, $grants, $this->admit());
    }

    private function saveFrom(array $preview, string $actor = 'root'): array {
        return $this->writer->setGroupGrants($preview['revision'], $actor, $preview['groupId'], $preview['grants'],
            $preview['previewId'], $this->admit());
    }

    /** A credential holder: its generations and one live grant of the current epoch at its last generation. */
    private function holder(string $uid, string $issue = '2', string $revoked = '0'): void {
        $this->database->dbal->insert('mcp_auth_users', ['user_key' => IdentityKey::user($uid), 'user_id' => $uid,
            'issue_seq' => $issue, 'revoked_below' => $revoked]);
        $this->token($uid, $issue);
    }

    private function token(string $uid, string $gen): void {
        (new OAuthStore($this->db))->insertToken(['user_id' => $uid, 'client_id' => 'client', 'resource' => 'https://cloud/', 'scope' => 'mcp',
            'access_hash' => bin2hex(random_bytes(32)), 'access_expires' => $this->now + 3600, 'refresh_hash' => bin2hex(random_bytes(32)),
            'refresh_expires' => $this->now + 86400, 'created_at' => $this->now, 'auth_global_epoch' => '0', 'auth_gen' => $gen], $this->now);
    }

    /** An issuance under the mutex, as consent does: issue_seq + 1 and a credential of that generation. */
    private function issue(string $uid): void {
        $this->writer->runInternal([], fn () => null, function () use ($uid): bool {
            $this->token($uid, (new AuthUsersMapper($this->db))->issue($uid));
            return false;
        });
    }

    private function generations(string $uid): array {
        return (new AuthUsersMapper($this->db))->generations($uid);
    }

    /** @return list<array> payloads of the stored events of one kind, in order */
    private function stored(string $kind): array {
        return array_map(static fn (array $row): array => json_decode($row['payload'], true),
            $this->database->dbal->fetchAllAssociative('SELECT payload FROM mcp_policy_events WHERE kind = ? ORDER BY id', [$kind]));
    }

    public function testAPreviewChangesNothingAndItsSaveActivatesTheGroupAtThePostCommitRevision(): void {
        $before = $this->revision();
        $preview = $this->preview(['notes' => ['read', 'edit', 'edit'], '_system' => ['eligible']]);
        $this->assertSame($before, $this->revision(), 'a preview is no policy change');
        $this->assertSame(['_system' => ['eligible'], 'notes' => ['edit', 'read']], $preview['grants'], 'canonical set');
        $this->assertSame(['revision' => $before, 'groupId' => 'staff', 'current' => [], 'state' => null, 'members' => 2,
            'trusted' => true, 'withdraws' => false, 'revokes' => 0],
            array_diff_key($preview, ['previewId' => 1, 'grants' => 1]));
        $this->assertSame([], $this->rows('staff'));
        $this->assertCount(1, $this->stored(PolicyEventsMapper::KIND_PREVIEW));

        $result = $this->saveFrom($preview);

        $revision = PolicyWriter::next($before);
        $this->assertSame(['revision' => $revision, 'changed' => true, 'state' => 'active', 'revoked' => 0], $result);
        $this->assertSame(['_system/eligible', 'notes/edit', 'notes/read'], $this->rows('staff'));
        $life = $this->life->get('staff');
        $this->assertSame('active', $life['state']);
        $this->assertSame($revision, $life['policyRev'], 'policy_rev is the revision of the commit, not the one before');
        $this->assertSame($revision, $this->revision());
        // Granting eligibility to the group writes no user preference: neither connected nor an override.
        $this->assertSame([], $this->configData->user);
        [$audit] = $this->stored(PolicyEventsMapper::KIND_AUDIT);
        $this->assertSame(['root', 'group_grants', $before, $revision], [$audit['actor'], $audit['action'], $audit['fromRevision'], $audit['revision']]);
        $this->assertSame([['gid' => 'staff', 'key' => 'group_grants', 'before' => null,
            'after' => '{"state":"active","grants":{"_system":["eligible"],"notes":["edit","read"]}}']], $audit['changes']);
    }

    /** T6: a group that cannot grant gets no preview and no save for a non-empty set; nothing is written. */
    public function testAGroupThatCannotGrantIsRefusedWithoutAnyWrite(): void {
        $revision = $this->revision();
        $this->trusted = false;
        try {
            $this->preview(['notes' => ['edit']]);
            $this->fail('An untrusted group was previewed');
        } catch (GroupSourceUntrusted $e) {
            $this->assertSame(422, $e->getCode());
        }
        $this->assertSame([], $this->stored(PolicyEventsMapper::KIND_PREVIEW));

        // The source changes between the preview and the save: the save reads the predicate again.
        $this->trusted = true;
        $preview = $this->preview(['notes' => ['edit']]);
        $this->trusted = false;
        try {
            $this->saveFrom($preview);
            $this->fail('An untrusted group was saved');
        } catch (GroupSourceUntrusted) {
        }
        $this->assertSame([], $this->rows('staff'));
        $this->assertNull($this->life->get('staff'));
        $this->assertSame($revision, $this->revision());
        $this->assertSame([], $this->stored(PolicyEventsMapper::KIND_AUDIT));
    }

    /** Clarification 5: "Confirm current policy" is a save of the current set; with the predicate false it is a 422. */
    public function testConfirmingTheCurrentSetReactivatesASuspendedGroupWithANewGen(): void {
        $gen = $this->policy('staff', GroupLifeMapper::NEEDS_CONFIRM);
        $preview = $this->preview(['_system' => ['eligible'], 'notes' => ['edit']]);
        $this->assertSame('needs_confirm', $preview['state']);
        $this->assertSame($preview['grants'], $preview['current']);

        $result = $this->saveFrom($preview);

        $this->assertSame(['changed' => true, 'state' => 'active', 'revoked' => 0], array_diff_key($result, ['revision' => 1]));
        $life = $this->life->get('staff');
        $this->assertSame('active', $life['state']);
        $this->assertNotSame($gen, $life['gen']);
        $this->assertSame($result['revision'], $life['policyRev']);
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'), 'same rows');

        $this->policy('team', GroupLifeMapper::NEEDS_CONFIRM);
        $revision = $this->revision();
        $this->trusted = false;
        try {
            $this->preview(['_system' => ['eligible'], 'notes' => ['edit']], 'team');
            $this->fail('An untrusted group was confirmed');
        } catch (GroupSourceUntrusted) {
        }
        $this->assertSame('needs_confirm', $this->life->get('team')['state']);
        $this->assertSame($revision, $this->revision());
    }

    /** Contracts §3.3.5: a save in pending_delete or needs_confirm substitutes the whole set of rows. */
    public function testASaveOfASuspendedGroupReplacesItsWholeSet(): void {
        $this->policy('staff', GroupLifeMapper::PENDING_DELETE, '0', [['notes', 'edit'], ['deck', 'read']]);
        $this->saveFrom($this->preview(['calendar' => ['read']]));
        $this->assertSame(['calendar/read'], $this->rows('staff'));
        $this->assertSame('active', $this->life->get('staff')['state']);
    }

    /** T16: a different member set between preview and save is a conflict, even with the same count. */
    public function testAChangedMemberSetOfTheSameCountIsAConflictWithNothingWritten(): void {
        $preview = $this->preview(['notes' => ['edit']]);
        $revision = $this->revision();
        $this->members = ['ana', 'carl'];
        try {
            $this->saveFrom($preview);
            $this->fail('A changed member set was saved');
        } catch (PolicyConflict) {
        }
        $this->assertSame([], $this->rows('staff'));
        $this->assertNull($this->life->get('staff'));
        $this->assertSame($revision, $this->revision());
        $this->members = ['bob', 'ana'];
        $this->assertTrue($this->saveFrom($preview)['changed'], 'the same members in another order are the same set');
    }

    /**
     * Ticket 07 F3: pending_delete does not move the revision, so the save compares the lifecycle it read in the
     * external phase again under the mutex. A deletion's pre-event between the phases replays the attempt, which then
     * no longer matches its preview: 409, nothing written.
     */
    public function testAPendingDeleteBetweenThePhasesIsSeenUnderTheMutex(): void {
        $gen = $this->policy('staff');
        $preview = $this->preview(['_system' => ['eligible'], 'notes' => ['edit', 'read']]);
        $revision = $this->revision();
        $this->during = [function () use ($gen): void {
            $this->assertTrue($this->life->markPendingDelete('staff', $gen, bin2hex(random_bytes(16))));
        }];
        $this->admitted = [];
        try {
            $this->saveFrom($preview);
            $this->fail('A save ignored a pending deletion');
        } catch (PolicyConflict) {
        }
        $this->assertCount(2, $this->admitted, 'the transition invalidated the first attempt under the mutex');
        $this->assertSame('pending_delete', $this->life->get('staff')['state']);
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'));
        $this->assertSame($revision, $this->revision());
    }

    /** Withdrawing eligibility cuts the holders the external phase found losing their last source, and only them. */
    public function testWithdrawingEligibilityCutsOnlyTheHoldersWhoLoseTheirLastSource(): void {
        $this->policy('staff');
        $this->holder('ana', '2');
        $this->holder('bob', '3');
        $this->holder('carl', '2', '2');
        $this->losing = ['ana', 'carl'];
        $preview = $this->preview(['notes' => ['edit']]);
        $this->assertTrue($preview['withdraws']);
        $this->assertSame(1, $preview['revokes']);
        $holders = $this->admitted[0]['holders'];
        sort($holders);
        $this->assertSame(['ana', 'bob'], $holders, 'every live holder not already cut, members or not');

        $result = $this->saveFrom($preview);

        $this->assertSame(1, $result['revoked']);
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '2'], $this->generations('ana'));
        $this->assertSame(['issue_seq' => '3', 'revoked_below' => '0'], $this->generations('bob'), 'another source keeps it');
        $this->assertSame(['notes/edit'], $this->rows('staff'));
        $this->assertSame([['v' => 1, 'scope' => 'user', 'uid' => 'ana', 'cut' => '2', 'epoch' => null]],
            $this->stored(PolicyEventsMapper::KIND_CLEANUP));
    }

    /** T5 F3: a member who consents between the external phase and the commit is a new holder in H1, and is cut. */
    public function testAConsentBetweenThePhasesReplaysTheWithdrawalAndCutsTheNewHolder(): void {
        $this->policy('staff');
        $this->holder('ana');
        $this->losing = ['ana', 'ursula'];
        $preview = $this->preview(['notes' => ['edit']]);
        $this->during = [fn () => $this->issue('ursula')];
        $this->admitted = [];

        $result = $this->saveFrom($preview);

        $this->assertCount(2, $this->admitted);
        $this->assertSame(['ana'], $this->admitted[0]['holders']);
        $this->assertContains('ursula', $this->admitted[1]['holders']);
        $this->assertSame(2, $result['revoked']);
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '2'], $this->generations('ursula'));
    }

    /** T5 F3 with connected already true: a holder's new consent only moves its issue_seq; H1 still differs. */
    public function testANewConsentOfAnExistingHolderBetweenThePhasesIsCutToo(): void {
        $this->policy('staff');
        $this->holder('ana', '2');
        $this->losing = ['ana'];
        $preview = $this->preview(['notes' => ['edit']]);
        $this->during = [fn () => $this->issue('ana')];
        $this->admitted = [];

        $this->saveFrom($preview);

        $this->assertCount(2, $this->admitted);
        $this->assertSame(['issue_seq' => '3', 'revoked_below' => '3'], $this->generations('ana'), 'the new credential is cut');
    }

    /** Contracts §2.3: holders that keep changing end, after two retries, in a 409 with nothing written. */
    public function testHoldersThatKeepChangingEndInAConflictAfterTwoRetries(): void {
        $this->policy('staff');
        $this->holder('ana');
        $this->losing = ['ana'];
        $preview = $this->preview(['notes' => ['edit']]);
        $revision = $this->revision();
        $this->during = [fn () => $this->issue('bob'), fn () => $this->issue('bob'), fn () => $this->issue('bob')];
        $this->admitted = [];
        try {
            $this->saveFrom($preview);
            $this->fail('A save went through changing holders');
        } catch (PolicyConflict) {
        }
        $this->assertCount(3, $this->admitted);
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'));
        $this->assertSame('0', $this->generations('ana')['revoked_below']);
        $this->assertSame($revision, $this->revision(), 'the issuances move no revision; the save wrote nothing');
    }

    public function testSavingTheSameSetOfAnActiveGroupIsANoOp(): void {
        $gen = $this->policy('staff');
        $revision = $this->revision();
        $result = $this->saveFrom($this->preview(['_system' => ['eligible'], 'notes' => ['edit']]));
        $this->assertSame(['revision' => $revision, 'changed' => false, 'state' => 'active', 'revoked' => 0], $result);
        $this->assertSame($gen, $this->life->get('staff')['gen']);
        $this->assertSame([], $this->stored(PolicyEventsMapper::KIND_AUDIT));
    }

    /** Contracts §3.3.5 ("until the administrator confirms or clears"): clearing works on a group that cannot grant. */
    public function testClearingRemovesTheRowsAndTheLifecycleEvenOfAGroupThatCannotGrant(): void {
        $this->policy('staff', GroupLifeMapper::NEEDS_CONFIRM);
        $this->trusted = false;
        $this->members = [];
        $revision = $this->revision();
        $preview = $this->preview([]);
        $this->assertTrue($preview['withdraws']);

        $result = $this->saveFrom($preview);

        $this->assertSame(['revision' => PolicyWriter::next($revision), 'changed' => true, 'state' => null, 'revoked' => 0], $result);
        $this->assertSame([], $this->rows('staff'));
        $this->assertNull($this->life->get('staff'));
        $this->assertFalse($this->saveFrom($this->preview([]))['changed'], 'nothing left: a no-op');
    }

    public function testAPreviewIsTheCallersOwnForTwoMinutesAndForItsExactCommand(): void {
        $preview = $this->preview(['notes' => ['edit']]);
        try {
            $this->saveFrom($preview, 'eve');
            $this->fail('Another administrator saved the preview');
        } catch (ReceiptNotFound) {
        }
        foreach ([['staff', ['notes' => ['read']]], ['team', ['notes' => ['edit']]]] as [$gid, $grants]) {
            try {
                $this->writer->setGroupGrants($preview['revision'], 'root', $gid, $grants, $preview['previewId'], $this->admit());
                $this->fail('A save differing from its preview was accepted');
            } catch (\InvalidArgumentException) {
            }
        }
        try {
            $this->writer->setGroupGrants($preview['revision'], 'root', 'staff', $preview['grants'], bin2hex(random_bytes(16)), $this->admit());
            $this->fail('An invented preview was accepted');
        } catch (ReceiptNotFound) {
        }
        $this->now += PolicyWriter::PREVIEW_TTL;
        try {
            $this->saveFrom($preview);
            $this->fail('An expired preview was saved');
        } catch (ReceiptExpired) {
        }
        $this->now -= PolicyWriter::PREVIEW_TTL;
        // Any policy change after the preview makes it stale.
        $this->save('team');
        try {
            $this->saveFrom($preview);
            $this->fail('A stale preview was saved');
        } catch (PolicyConflict) {
        }
        $this->assertSame([], $this->rows('staff'));
    }

    public function testAnInvalidSetIsRefusedBeforeAnything(): void {
        $lock = $this->lockSeq();
        foreach ([['files' => ['nuke']], ['_system' => ['admin']], ['notes' => 'edit'], ['notes' => ['x' => 'edit']], 'notes', [['edit']]] as $grants) {
            try {
                $this->preview($grants);
                $this->fail('Invalid set accepted: ' . json_encode($grants));
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame($lock, $this->lockSeq(), 'refused before the mutex');
        $this->assertSame([], PolicyEventPayload::groupGrants(['notes' => []]), 'a module without operations is no grant');
    }

    /**
     * Review F1: a holder kept by another group (G2) when G1 stops granting. G2's deletion pre-event between the phases
     * moves its lifecycle (no revision step); the save sees it under the mutex and replays, and with G2 suspended the
     * holder's eligibility without G1 is unknown: 503, nothing written, nobody cut. Once G2 is purged the withdrawal
     * cuts the holder, and granting eligibility again never brings the old credential back.
     */
    public function testASourceThatKeptAHolderAndMovesBeforeTheCommitIsSeenAndNeverLeavesTheHolderUncut(): void {
        $this->policy('staff');
        $teamGen = $this->policy('team');
        $this->holder('ana', '2');
        $this->memberOf['ana'] = ['staff', 'team'];
        $this->members = ['ana'];
        [$preview, $save] = $this->realSave('staff', []);
        $this->assertSame(0, $preview['revokes'], 'team keeps ana');
        $pending = bin2hex(random_bytes(16));
        $this->after = [fn () => $this->assertTrue($this->life->markPendingDelete('team', $teamGen, $pending))];
        $this->admitted = [];
        $revision = $this->revision();
        try {
            $save();
            $this->fail('A withdrawal decided on a source that moved');
        } catch (PolicyUnavailable $e) {
            $this->assertFalse($e->uncertain);
        }
        $this->assertCount(2, $this->admitted, 'team moved under the first attempt: replayed, then unknown');
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('staff'));
        $this->assertSame('0', $this->generations('ana')['revoked_below']);
        $this->assertSame($revision, $this->revision());

        $this->assertTrue($this->lifecycle->deleted('team', $pending));
        [$preview, $save] = $this->realSave('staff', []);
        $this->assertSame(1, $preview['revokes']);
        $this->assertSame(1, $save()['revoked']);
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '2'], $this->generations('ana'));

        [, $save] = $this->realSave('staff', ['_system' => ['eligible']]);
        $this->assertTrue($save()['changed']);
        $this->assertSame('2', $this->generations('ana')['revoked_below'], 'the regrant leaves the old generation 2 cut');
    }

    /**
     * Review F1, the commit side: every active source the decision relied on gets a new gen, so a deletion's pre-event
     * that read it before the commit fails its conditional transition; its After then suspends the group (what
     * GroupLifecycleListener does without a recorded gen) instead of purging a source without a cause for its holders.
     */
    public function testASaveRenewsTheGenOfEverySourceItReliedOn(): void {
        $this->policy('staff');
        $teamGen = $this->policy('team');
        $this->holder('ana');
        $this->memberOf['ana'] = ['staff', 'team'];
        $this->members = ['ana'];
        [, $save] = $this->realSave('staff', []);
        $this->assertSame(0, $save()['revoked']);
        $team = $this->life->get('team');
        $this->assertSame('active', $team['state']);
        $this->assertNotSame($teamGen, $team['gen']);
        $this->assertFalse($this->life->markPendingDelete('team', $teamGen, bin2hex(random_bytes(16))));
        $this->assertTrue($this->lifecycle->needsConfirm('team'));
        $this->assertSame(['_system/eligible', 'notes/edit'], $this->rows('team'), 'suspended, never purged');
    }

    /**
     * Review F2: the lifecycle write is conditioned on the gen read under the mutex. A pre-event landing after that
     * read (injected right before the write, on the writer's own SQLite connection) makes it touch no row, and the
     * attempt is replayed instead of overwriting pending_delete. Here the injection is rolled back with the attempt, so
     * the replay saves; on two real connections the pre-event stays and the replay is a 409 (integration).
     */
    public function testALifecycleMovedAfterItsReadUnderTheMutexIsNeverOverwritten(): void {
        $this->policy('staff');
        $preview = $this->preview(['_system' => ['eligible'], 'notes' => ['edit', 'read']]);
        $this->during = [function (): void {
            // Armed at the mutex (the attempt's first statement), it fires at the next one: the lifecycle write.
            $this->database->beforeStatement = function (): void {
                $this->database->beforeStatement = function (): void {
                    $this->database->dbal->update('mcp_group_life', ['state' => 'pending_delete', 'gen' => bin2hex(random_bytes(16))],
                        ['group_key' => IdentityKey::group('staff')]);
                    $this->injected = true;
                };
            };
        }];
        $this->admitted = [];
        $result = $this->saveFrom($preview);
        $this->assertTrue($this->injected);
        $this->assertCount(2, $this->admitted, 'the conditional write refused the moved lifecycle');
        $this->assertTrue($result['changed']);
    }

    /**
     * Re-review R1, the probe of the review: G2's deletion Before judges the holder while G1 still grants it (no
     * cause), then a withdrawal of G1 commits, keeps the holder through G2 and renews G2's gen, so the Before loses its
     * conditional transition. The Before judges the holders again against the new state: the holder now depends on G2
     * alone and gets its cause; the After purges G2 and cuts it. Granting G1 again never brings the old credential back.
     */
    public function testADeletionBeforeThatLosesItsTransitionToAWithdrawalJudgesTheHoldersAgain(): void {
        $this->policy('staff');
        $this->policy('team');
        $this->holder('ana', '2');
        $this->memberOf['ana'] = ['staff', 'team'];
        $this->members = ['ana'];
        [$preview, $save] = $this->realSave('staff', []);
        $this->assertSame(0, $preview['revokes'], 'team keeps ana');
        $life = new class($this->db, $save) extends GroupLifeMapper {
            public function __construct(\OCP\IDBConnection $db, private ?\Closure $between) { parent::__construct($db); }
            public function markPendingDelete(string $gid, string $expectedGen, string $gen): bool {
                if ($this->between !== null) {
                    // The withdrawal commits between the Before's judgement of the holders and its transition.
                    [$between, $this->between] = [$this->between, null];
                    \PHPUnit\Framework\Assert::assertSame(0, $between()['revoked']);
                }
                return parent::markPendingDelete($gid, $expectedGen, $gen);
            }
        };
        [$listener] = $this->listener($life);
        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getGID')->willReturn('team');

        $listener->handle(new \OCP\Group\Events\BeforeGroupDeletedEvent($group));
        $this->assertSame([], $this->rows('staff'), 'the withdrawal committed');
        $this->assertSame('pending_delete', $this->life->get('team')['state'], 'the second round marked it');
        $this->assertSame([IdentityKey::group('team')], array_column($this->rechecks->causes('ana'), 'causeKey'));
        $this->memberOf['ana'] = ['staff'];
        $listener->handle(new \OCP\Group\Events\GroupDeletedEvent($group));

        $this->assertNull($this->life->get('team'));
        $this->assertSame([], $this->rechecks->causes('ana'));
        $this->assertSame(['issue_seq' => '2', 'revoked_below' => '2'], $this->generations('ana'), 'cut by its cause');
        [, $save] = $this->realSave('staff', ['_system' => ['eligible']]);
        $save();
        $this->assertSame('2', $this->generations('ana')['revoked_below'], 'the regrant leaves the old generation 2 cut');
    }

    /**
     * Re-review 2, R2: the first round of a deletion Before records the holder's cause C; before its transition an
     * issuance (runCredential with the real classifier) consumes C, cuts generation 2 and issues 3, and a renewal of the
     * group's gen makes the transition lose. The second round never writes C back, so the After finds nothing to do
     * and the new credential (generation 3) survives.
     */
    public function testACauseAnIssuanceConsumedBetweenTheRoundsIsNeverWrittenBack(): void {
        $this->policy('team');
        $this->holder('ana', '2');
        $this->memberOf['ana'] = ['team'];
        $this->members = ['ana'];
        $causes = null;
        $life = $this->interleaved(function () use (&$causes): void {
            $this->assertCount(1, $this->rechecks->causes('ana'), 'the first round recorded C');
            $this->writer->runCredential('ana', fn () => null, function (): bool {
                $this->token('ana', (new AuthUsersMapper($this->db))->issue('ana'));
                return false;
            }, $causes->classifier());
            $this->assertSame([], $this->rechecks->causes('ana'), 'the issuance consumed C');
            $this->assertSame(['issue_seq' => '3', 'revoked_below' => '2'], $this->generations('ana'));
            $this->writer->runInternal([], fn () => null, fn (): bool => $this->life->transition('team',
                $this->life->get('team')['gen'], GroupLifeMapper::ACTIVE, bin2hex(random_bytes(16))));
        });
        [$listener, $causes] = $this->listener($life);
        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getGID')->willReturn('team');

        $listener->handle(new \OCP\Group\Events\BeforeGroupDeletedEvent($group));
        $this->assertSame('pending_delete', $this->life->get('team')['state'], 'the second round marked it');
        $this->assertSame([], $this->rechecks->causes('ana'), 'C is never written back');
        $this->memberOf['ana'] = [];
        $listener->handle(new \OCP\Group\Events\GroupDeletedEvent($group));

        $this->assertNull($this->life->get('team'));
        $this->assertSame(['issue_seq' => '3', 'revoked_below' => '2'], $this->generations('ana'), 'generation 3 survives');
    }
}
