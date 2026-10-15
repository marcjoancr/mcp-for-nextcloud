<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Service\Policy;

use OCA\Mcp\Db\LogsGroupsMapper;
use OCA\Mcp\Service\LogsAccess;
use OCA\Mcp\Service\Policy\LogsRoleReader;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\Group\Backend\IIsAdminBackend;
use OCP\GroupInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Security\Ip\IRemoteAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogsRoleReaderTest extends TestCase {
    /** @var list<string> */
    private array $ids = [];

    public static function cases(): iterable {
        foreach (['file', 'syslog', 'errorlog', 'systemd', 'custom'] as $type) {
            foreach ([true, false] as $remote) {
                foreach ([true, false] as $nativeBackend) {
                    foreach ([[], ['admin'], ['logs'], ['admin', 'logs'], ['unrelated']] as $ids) {
                        foreach (['alice', 'bob', null] as $sessionUid) {
                            foreach ([true, false] as $strict) {
                                yield [$type, $remote, $nativeBackend, $ids, $sessionUid, $strict];
                            }
                        }
                    }
                }
            }
        }
    }

    private function session(?string $uid): IUserSession {
        $session = $this->createMock(IUserSession::class);
        $user = null;
        if ($uid !== null) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
        }
        $session->method('getUser')->willReturn($user);
        return $session;
    }

    /**
     * A transcription of OC\Group\Manager::isAdmin and ::isInGroup (stable32–35), not of the reader: the
     * remote-address restriction only applies to the session user; isInGroup is loose on NC32–34, strict on NC35.
     */
    private function coreManager(IRemoteAddress $remote, IUserSession $session, array $backends, bool $strict): IGroupManager {
        $manager = $this->createMock(IGroupManager::class);
        $isInGroup = fn ($userId, $group): bool => $strict ? in_array($group, $this->ids, true) : in_array($group, $this->ids);
        $manager->method('isInGroup')->willReturnCallback($isInGroup);
        $manager->method('isAdmin')->willReturnCallback(static function ($userId) use ($remote, $session, $backends, $isInGroup): bool {
            if (!$remote->allowsAdminActions()
                && ($userId === $session->getUser()?->getUID())) {
                return false;
            }
            foreach ($backends as $backend) {
                if (is_string($userId) && $backend->implementsActions(GroupInterface::IS_ADMIN) && $backend->isAdmin($userId)) {
                    return true;
                }
            }
            return $isInGroup($userId, 'admin');
        });
        $manager->method('getBackends')->willReturn($backends);
        return $manager;
    }

    /** @return array{0:LogsAccess,1:LogsRoleReader} */
    private function pair(string $type, bool $remoteAllowed, bool $nativeAdmin, ?string $sessionUid, bool $strict, array $listed): array {
        $data = new InMemoryConfig();
        $data->system['log_type'] = $type;
        $config = $data->mock($this);
        $mapper = $this->createMock(LogsGroupsMapper::class);
        $mapper->method('state')->willReturn(['groups' => $listed, 'version' => 1]);
        $backend = $this->createMockForIntersectionOfInterfaces([GroupInterface::class, IIsAdminBackend::class]);
        $backend->method('implementsActions')->with(GroupInterface::IS_ADMIN)->willReturn(true);
        $backend->method('isAdmin')->with('alice')->willReturn($nativeAdmin);
        $remote = $this->createMock(IRemoteAddress::class);
        $remote->method('allowsAdminActions')->willReturn($remoteAllowed);
        $session = $this->session($sessionUid);
        $manager = $this->coreManager($remote, $session, [$backend], $strict);
        $logs = new LogsAccess($config, $manager, $mapper, $this->createMock(ILockingProvider::class));
        return [$logs, new LogsRoleReader($logs, $manager, $remote, $session)];
    }

    #[DataProvider('cases')]
    public function testFreshAdapterMatchesLegacyLogGate(string $type, bool $remoteAllowed, bool $nativeAdmin, array $ids,
        ?string $sessionUid, bool $strict): void {
        $this->ids = $ids;
        [$logs, $reader] = $this->pair($type, $remoteAllowed, $nativeAdmin, $sessionUid, $strict, ['logs']);
        $this->assertSame(['available' => $logs->available(), 'permitted' => $logs->permits('alice')], $reader->read('alice', $ids));
    }

    public function testAnotherUsersAdministrationIsNotSubjectToTheViewersRemoteAddress(): void {
        $this->ids = ['admin'];
        [$logs, $reader] = $this->pair('file', false, false, 'bob', false, []);
        $this->assertTrue($logs->permits('alice'));
        $this->assertTrue($reader->read('alice', ['admin'])['permitted']);
        [$logs, $reader] = $this->pair('file', false, false, 'alice', false, []);
        $this->assertFalse($logs->permits('alice'));
        $this->assertFalse($reader->read('alice', ['admin'])['permitted']);
    }

    /** Intentional, fail-closed divergence from the loose isInGroup of NC32–34: "01" and "1" are distinct groups. */
    public function testNumericGroupIdsCompareStrictlyOnEveryVersion(): void {
        $this->ids = ['1'];
        [$logs, $reader] = $this->pair('file', true, false, null, false, ['01']);
        $this->assertTrue($logs->permits('alice'), 'NC32–34 core matches "01" == "1"');
        $this->assertFalse($reader->read('alice', ['1'])['permitted']);
        [$logs, $reader] = $this->pair('file', true, false, null, true, ['01']);
        $this->assertFalse($logs->permits('alice'));
        $this->assertFalse($reader->read('alice', ['1'])['permitted']);
        $this->assertTrue($reader->read('alice', ['01'])['permitted']);
    }

    public function testSameReaderUsesNewMembershipRemoteAddressAndRoleStateOnEveryOperation(): void {
        $logs = $this->createMock(LogsAccess::class);
        $logs->method('available')->willReturn(true);
        $logs->method('groups')->willReturn(['logs']);
        $logs->expects($this->never())->method('permits');
        $manager = $this->createMock(IGroupManager::class);
        $manager->method('getBackends')->willReturn([]);
        $manager->expects($this->never())->method('isAdmin');
        $manager->expects($this->never())->method('isInGroup');
        $allowed = true;
        $remote = $this->createMock(IRemoteAddress::class);
        $remote->method('allowsAdminActions')->willReturnCallback(static function () use (&$allowed): bool { return $allowed; });
        $reader = new LogsRoleReader($logs, $manager, $remote, $this->session('alice'));
        $this->assertTrue($reader->read('alice', ['admin'])['permitted']);
        $allowed = false;
        $this->assertFalse($reader->read('alice', ['admin'])['permitted']);
        // Existing explicit logs roles are independent of the admin-address gate.
        $this->assertTrue($reader->read('alice', ['logs'])['permitted']);
        $this->assertFalse($reader->read('alice', [])['permitted']);
        $this->assertFalse($reader->read(null, ['admin', 'logs'])['permitted']);
    }
}
