<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OC\Group\Database;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Service\LogsAccess;
use OCA\Mcp\Service\Policy\GroupSourceClassifier;
use OCA\Mcp\Service\Policy\LogsRoleReader;
use OCA\Mcp\Service\Policy\MembershipReader;
use OCA\Mcp\Service\Policy\PolicyResolver;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Group\Backend\INamedBackend;
use OCP\GroupInterface;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

/** T6/T14: the real manager/backend and lifecycle storage, with repeated operations in one process. */
final class GroupResolutionTest extends IntegrationTestCase {
    private string $gid = '';
    private ?\stdClass $extraState = null;

    protected function tearDown(): void {
        if ($this->extraState !== null) {
            $this->extraState->serves = false;
        }
        parent::tearDown();
        if ($this->gid !== '') {
            foreach (['mcp_group_grants', 'mcp_group_life'] as $table) {
                $qb = $this->official()->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($this->gid))))->executeStatement();
            }
            Server::get(IGroupManager::class)->get($this->gid)?->delete();
        }
    }

    private function group(): void {
        $this->gid = 'mcp-it-resolution-' . bin2hex(random_bytes(8));
        $group = Server::get(IGroupManager::class)->createGroup($this->gid);
        $this->assertNotNull($group);
        $group->addUser(Server::get(IUserManager::class)->get(Fixtures::ALICE));
    }

    /** Lists the group as granting eligibility, active, through the writer like an administrator would. */
    private function grantEligibility(): GroupLifeMapper {
        $db = $this->official();
        $life = new GroupLifeMapper($db);
        Server::get(PolicyWriter::class)->runInternal([], fn () => null, function ($snapshot, $state) use ($db, $life): bool {
            $qb = $db->getQueryBuilder();
            $qb->insert('mcp_group_grants')
                ->setValue('group_key', $qb->createNamedParameter(IdentityKey::group($this->gid)))
                ->setValue('group_id', $qb->createNamedParameter($this->gid))
                ->setValue('module', $qb->createNamedParameter('_system'))
                ->setValue('operation', $qb->createNamedParameter('eligible'))->executeStatement();
            $life->put($this->gid, 'active', bin2hex(random_bytes(16)), $state['revision']);
            return true;
        });
        return $life;
    }

    public function testActiveDatabaseGroupGrantsEligibilityThroughTheRealManager(): void {
        $this->group();
        $this->grantEligibility();
        $context = Server::get(PolicyResolver::class)->begin(Fixtures::ALICE);
        try {
            $eligible = $context->eligible();
            $this->assertTrue($eligible->allowed);
            $this->assertSame('group', $eligible->source);
            $this->assertSame([$this->gid], $eligible->groupIds);
        } finally { $context->close(); }
    }

    /** M1: the fresh logs role matches LogsAccess over the real OC\Group\Manager for admins and non-admins. */
    public function testLogsRoleReaderMatchesLegacyGateOnTheRealManager(): void {
        $manager = Server::get(IGroupManager::class);
        $users = Server::get(IUserManager::class);
        $legacy = Server::get(LogsAccess::class);
        $reader = Server::get(LogsRoleReader::class);
        $membership = new MembershipReader($manager);
        $uids = [Fixtures::ALICE, Fixtures::BOB];
        foreach ($manager->get('admin')?->getUsers() ?? [] as $admin) {
            $uids[] = $admin->getUID();
            break;
        }
        $this->assertGreaterThan(2, count($uids), 'the instance needs an administrator to compare');
        foreach ($uids as $uid) {
            $user = $users->get($uid);
            $this->assertNotNull($user);
            $this->assertSame(['available' => $legacy->available(), 'permitted' => $legacy->permits($uid)],
                $reader->read($uid, $membership->read($user)), $uid);
        }
    }

    public function testFreshMembershipBypassesAPreloadedManagerAcrossOperations(): void {
        $this->group();
        $manager = Server::get(IGroupManager::class);
        $alice = Server::get(IUserManager::class)->get(Fixtures::ALICE);
        $this->assertContains($this->gid, $manager->getUserGroupIds($alice));
        $reader = new MembershipReader($manager);
        $this->assertContains($this->gid, $reader->read($alice));
        // An independent backend object changes storage without resetting the preloaded manager.
        $backend = new Database($this->official());
        $this->assertTrue($backend->removeFromGroup(Fixtures::ALICE, $this->gid));
        $this->assertNotContains($this->gid, $reader->read($alice));
        $this->assertTrue($backend->addToGroup(Fixtures::ALICE, $this->gid));
        $this->assertContains($this->gid, $reader->read($alice));
        $this->assertNotContains($this->gid, $reader->read($alice, true, [], [$this->gid]));
    }

    public function testDuplicateDatabaseSourcePersistsNeedsConfirmAndNeverReactivatesByItself(): void {
        $this->group();
        $manager = Server::get(IGroupManager::class);
        $manager->get($this->gid); // Cached IGroup must not be used for classification.
        $classifier = new GroupSourceClassifier($manager);
        $this->assertTrue($classifier->trusted($this->gid));
        $life = $this->grantEligibility();
        // removeBackend is new in NC35. Keep this test backend registered but inert after the test,
        // using only APIs available across NC32–35; never clear the real manager's backends or caches.
        $extra = $this->createMockForIntersectionOfInterfaces([GroupInterface::class, INamedBackend::class]);
        $state = $this->extraState = (object)['serves' => true, 'gid' => $this->gid];
        $extra->method('getBackendName')->willReturn('Database');
        $extra->method('groupExists')->willReturnCallback(static fn ($gid) => $state->serves && $gid === $state->gid);
        $extra->method('getUserGroups')->willReturnCallback(static fn ($uid) => $state->serves && $uid === Fixtures::ALICE ? [$state->gid] : []);
        $extra->method('getGroups')->willReturn([]);
        $manager->addBackend($extra);
        $this->assertFalse($classifier->trusted($this->gid));
        $resolver = Server::get(PolicyResolver::class);
        $context = $resolver->begin(Fixtures::ALICE);
        try {
            $this->assertNull($context->eligible()->allowed);
            $this->assertSame('needs_confirm', $life->get($this->gid)['state']);
        } finally { $context->close(); }
        $state->serves = false;
        $this->assertTrue($classifier->trusted($this->gid));
        $context = $resolver->begin(Fixtures::ALICE);
        try { $this->assertNull($context->eligible()->allowed); }
        finally { $context->close(); }
    }

    public function testPositiveDatabaseExistenceCacheIsADeclaredLongProcessLimit(): void {
        $this->group();
        $cached = new Database($this->official());
        $this->assertTrue($cached->groupExists($this->gid));
        $external = new Database($this->openIndependentConnection());
        $this->assertTrue($external->deleteGroup($this->gid));
        $this->assertTrue($cached->groupExists($this->gid));
        $this->assertFalse((new Database($this->official()))->groupExists($this->gid));
    }
}
