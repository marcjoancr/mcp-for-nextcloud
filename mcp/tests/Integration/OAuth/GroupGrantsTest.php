<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\OAuth;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\OAuth\CredentialGeneration;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Service\Policy\GroupGrantsService;
use OCA\Mcp\Service\Policy\GroupLifecycle;
use OCA\Mcp\Service\Policy\GroupSourceUntrusted;
use OCA\Mcp\Service\Policy\PolicyConflict;
use OCA\Mcp\Service\Policy\PolicyResolver;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCP\Config\IUserConfig;
use OCP\Group\Backend\INamedBackend;
use OCP\GroupInterface;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

/**
 * Ticket 08 on the real server: the group preview and save through the container's writer and service, over the core
 * group manager and its Database backend (validation T5 part F3, T6 422 without write, T16 server part). Every
 * observation is read from a second session.
 *
 * Group eligibility is not wired into consent and validation yet (ticket 09), which still read the individual flag:
 * where a holder must be eligible only through the group, the individual flag is removed after the consent, outside
 * the writer (as GroupLifecycleTest does), and the effect of a withdrawal is read on the generations (the cut).
 */
final class GroupGrantsTest extends OAuthAccountTestCase {
    private const ACTOR = 'admin';
    /** @var list<string> */
    private array $groups = [];
    private ?\stdClass $extraState = null;

    protected function tearDown(): void {
        if ($this->extraState !== null) {
            $this->extraState->serves = false;
        }
        foreach ($this->groups as $gid) {
            foreach (['mcp_group_grants', 'mcp_group_life'] as $table) {
                $qb = $this->official()->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($gid))))->executeStatement();
            }
            Server::get(IGroupManager::class)->get($gid)?->delete();
        }
        parent::tearDown();
    }

    /** A Database group holding the test user. */
    private function group(): IGroup {
        $gid = 'mcp-it-grants-' . bin2hex(random_bytes(6));
        $this->groups[] = $gid;
        $group = Server::get(IGroupManager::class)->createGroup($gid);
        $this->assertNotNull($group);
        $group->addUser($this->user);
        return $group;
    }

    /**
     * The production external phase, with hooks run before it, one per attempt.
     *
     * @param list<\Closure(): void> $hooks
     */
    private function admit(array &$hooks = [], ?int &$attempts = null): \Closure {
        $attempts = 0;
        $service = Server::get(GroupGrantsService::class);
        return function (array $command) use (&$hooks, &$attempts, $service): array {
            ++$attempts;
            if ($hooks !== []) {
                (array_shift($hooks))();
            }
            return $service->admit($command);
        };
    }

    private function preview(string $gid, array $grants): array {
        return Server::get(GroupGrantsService::class)->preview($this->state()['revision'], self::ACTOR, $gid, $grants);
    }

    private function saveFrom(array $preview, ?\Closure $admit = null): array {
        return Server::get(PolicyWriter::class)->setGroupGrants($preview['revision'], self::ACTOR, $preview['groupId'],
            $preview['grants'], $preview['previewId'], $admit ?? $this->admit());
    }

    private function groupOnly(): void {
        Server::get(IConfig::class)->deleteUserValue($this->uid, 'mcp', 'eligible');
        Server::get(IUserConfig::class)->clearCache($this->uid, false);
    }

    private function life(string $gid): ?array {
        return (new GroupLifeMapper($this->openIndependentConnection()))->get($gid);
    }

    private function grants(string $gid): array {
        return (new GroupGrantsMapper($this->openIndependentConnection()))->forGroups([$gid])[0]['grants'];
    }

    private function state(): array {
        return (new PolicyStateMapper($this->openIndependentConnection()))->state();
    }

    private function generations(): array {
        return (new AuthUsersMapper($this->openIndependentConnection()))->generations($this->uid);
    }

    private function live(array $tokens): bool {
        $row = Server::get(OAuthStore::class)->findByAccess(Server::get(TokenHasher::class)->hash((string)$tokens['access_token']));
        return $row !== null && CredentialGeneration::live($row, $this->generations(), Server::get(PolicyStateMapper::class)->state()['auth_epoch']);
    }

    /** @return array<string, string> the user's raw mcp preferences, through the official config after a cache clear */
    private function preferences(): array {
        Server::get(IUserConfig::class)->clearCache($this->uid, false);
        $config = Server::get(IConfig::class);
        $values = [];
        foreach ($config->getUserKeys($this->uid, 'mcp') as $key) {
            $values[$key] = $config->getUserValue($this->uid, 'mcp', $key);
        }
        ksort($values, SORT_STRING);
        return $values;
    }

    public function testASaveActivatesTheGroupAtThePostCommitRevisionAndTouchesNoUserPreference(): void {
        $this->exchange($this->consent());
        $gid = $this->group()->getGID();
        $preferences = $this->preferences();
        $preview = $this->preview($gid, ['notes' => ['edit'], '_system' => ['eligible']]);
        $this->assertSame(['members' => 1, 'trusted' => true, 'withdraws' => false, 'revokes' => 0],
            array_intersect_key($preview, ['members' => 1, 'trusted' => 1, 'withdraws' => 1, 'revokes' => 1]));
        $this->assertSame($preview['revision'], $this->state()['revision'], 'a preview moves no revision');

        $result = $this->saveFrom($preview);

        $this->assertSame(PolicyWriter::next($preview['revision']), $result['revision']);
        $this->assertSame($result['revision'], $this->state()['revision']);
        $life = $this->life($gid);
        $this->assertSame(['active', $result['revision']], [$life['state'], $life['policyRev']], 'policy_rev = post-commit revision');
        $this->assertSame(['_system' => ['eligible'], 'notes' => ['edit']], $this->grants($gid));
        $this->assertSame($preferences, $this->preferences(), 'connected and every override untouched');
        $context = Server::get(PolicyResolver::class)->begin($this->uid);
        try {
            $this->assertSame([true, 'group', [$gid]], [$context->granted('notes', 'edit')->allowed,
                $context->granted('notes', 'edit')->source, $context->granted('notes', 'edit')->groupIds]);
        } finally {
            $context->close();
        }
    }

    /** T6: a second backend serving the same id makes the group untrusted: 422 at preview and at save, nothing written. */
    public function testAGroupServedByTwoBackendsIsRefusedWithoutAnyWrite(): void {
        $gid = $this->group()->getGID();
        $preview = $this->preview($gid, ['notes' => ['edit']]);
        $before = $this->state();
        $this->extraState = (object)['serves' => true, 'gid' => $gid];
        Server::get(IGroupManager::class)->addBackend(new InertWhenDoneDatabaseBackend($this->extraState));
        foreach ([fn () => $this->preview($gid, ['notes' => ['edit']]), fn () => $this->saveFrom($preview)] as $command) {
            try {
                $command();
                $this->fail('An untrusted group was accepted');
            } catch (GroupSourceUntrusted) {
            }
        }
        $this->assertSame([], $this->grants($gid));
        $this->assertNull($this->life($gid));
        $this->assertSame($before['revision'], $this->state()['revision']);
    }

    /** T16: replacing the only member by another one keeps the count, and is still a conflict. */
    public function testAMemberSwapOfTheSameCountBetweenPreviewAndSaveIsAConflict(): void {
        $group = $this->group();
        $preview = $this->preview($group->getGID(), ['notes' => ['edit']]);
        $group->removeUser($this->user);
        $group->addUser(Server::get(IUserManager::class)->get(Fixtures::BOB));
        $this->assertSame(1, $group->count());
        try {
            $this->saveFrom($preview);
            $this->fail('A changed member set was saved');
        } catch (PolicyConflict) {
        }
        $this->assertSame([], $this->grants($group->getGID()));
        $this->assertSame($preview['revision'], $this->state()['revision']);
    }

    /**
     * T5 F3: the member consents again (connected already true) between the external phase of a withdrawal and its
     * commit. The issuance moves the holder's issue_seq, H1 differs from H0, the attempt is replayed, and the new
     * credential is cut with the old one.
     */
    public function testAConsentBetweenThePhasesOfAWithdrawalIsReplayedAndCut(): void {
        $old = $this->exchange($this->consent());
        $gid = $this->group()->getGID();
        $this->saveFrom($this->preview($gid, ['_system' => ['eligible']]));
        $this->groupOnly();
        $preview = $this->preview($gid, []);
        $this->assertTrue($preview['withdraws']);
        $this->assertSame(1, $preview['revokes']);
        $new = null;
        $hooks = [function () use (&$new): void {
            // Consent still reads the individual flag (ticket 09): grant it for the consent, then take it back.
            Server::get(IConfig::class)->setUserValue($this->uid, 'mcp', 'eligible', '1');
            Server::get(IUserConfig::class)->clearCache($this->uid, false);
            $new = $this->exchange($this->consent());
            $this->groupOnly();
        }];

        $result = $this->saveFrom($preview, $this->admit($hooks, $attempts));

        $this->assertSame(2, $attempts, 'H1 differed: one replay');
        $this->assertSame(1, $result['revoked']);
        $generations = $this->generations();
        $this->assertSame($generations['issue_seq'], $generations['revoked_below'], 'the cut covers the new credential');
        $this->assertFalse($this->live($old));
        $this->assertFalse($this->live($new));
        $this->assertSame([], $this->grants($gid));
        $this->assertNull($this->life($gid));
    }

    /** T5: a holder still eligible individually keeps its session when the group stops granting eligibility. */
    public function testAHolderWithAnIndividualAllowKeepsItsSession(): void {
        $tokens = $this->exchange($this->consent());
        $gid = $this->group()->getGID();
        $this->saveFrom($this->preview($gid, ['_system' => ['eligible']]));
        $revoked = $this->generations()['revoked_below'];
        $preview = $this->preview($gid, []);
        $this->assertSame(0, $preview['revokes']);
        $this->assertSame(0, $this->saveFrom($preview)['revoked']);
        $this->assertSame($revoked, $this->generations()['revoked_below']);
        $this->assertSame($this->uid, $this->bearer($tokens));
    }

    /** Clarification 5: "Confirm current policy" brings a suspended group back to active with a new gen, same rows. */
    public function testConfirmingASuspendedGroupReactivatesItWithANewGen(): void {
        $gid = $this->group()->getGID();
        $this->saveFrom($this->preview($gid, ['_system' => ['eligible'], 'notes' => ['edit']]));
        $this->assertTrue(Server::get(GroupLifecycle::class)->needsConfirm($gid));
        $suspended = $this->life($gid);
        $this->assertSame('needs_confirm', $suspended['state']);
        $preview = $this->preview($gid, $this->grants($gid));
        $this->assertSame('needs_confirm', $preview['state']);

        $result = $this->saveFrom($preview);

        $life = $this->life($gid);
        $this->assertSame(['active', $result['revision']], [$life['state'], $life['policyRev']]);
        $this->assertNotSame($suspended['gen'], $life['gen']);
        $this->assertSame(['_system' => ['eligible'], 'notes' => ['edit']], $this->grants($gid));
    }

    /** Ticket 07 F3: a deletion's pre-event between the phases (no revision step) is seen under the mutex: 409. */
    public function testAPendingDeleteBetweenThePhasesIsAConflict(): void {
        $gid = $this->group()->getGID();
        $this->saveFrom($this->preview($gid, ['_system' => ['eligible']]));
        $preview = $this->preview($gid, ['_system' => ['eligible'], 'notes' => ['edit']]);
        $hooks = [function () use ($gid): void {
            $life = new GroupLifeMapper($this->official());
            $this->assertTrue($life->markPendingDelete($gid, $life->get($gid)['gen'], bin2hex(random_bytes(16))));
        }];
        try {
            $this->saveFrom($preview, $this->admit($hooks, $attempts));
            $this->fail('A save ignored a pending deletion');
        } catch (PolicyConflict) {
        }
        $this->assertSame(2, $attempts);
        $this->assertSame('pending_delete', $this->life($gid)['state']);
        $this->assertSame(['_system' => ['eligible']], $this->grants($gid));
        $this->assertSame($preview['revision'], $this->state()['revision']);
    }
}

/** A second backend named Database for one group while $state->serves; afterwards it answers like an empty backend. */
final class InertWhenDoneDatabaseBackend implements GroupInterface, INamedBackend {
    public function __construct(private \stdClass $state) {}
    public function getBackendName(): string {
        return 'Database';
    }
    public function implementsActions($actions) {
        return false;
    }
    public function inGroup($uid, $gid) {
        return false;
    }
    public function getUserGroups($uid) {
        return [];
    }
    public function getGroups(string $search = '', int $limit = -1, int $offset = 0) {
        return [];
    }
    public function groupExists($gid) {
        return $this->state->serves && $gid === $this->state->gid;
    }
    public function usersInGroup($gid, $search = '', $limit = -1, $offset = 0) {
        return [];
    }
}
