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
use OCP\Lock\ILockingProvider;
use OCP\Security\Ip\IRemoteAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogsRoleReaderTest extends TestCase {
    public static function cases(): iterable {
        foreach (['file', 'syslog', 'errorlog', 'systemd', 'custom'] as $type) {
            foreach ([true, false] as $remote) {
                foreach ([true, false] as $nativeBackend) {
                    foreach ([[], ['admin'], ['logs'], ['admin', 'logs'], ['unrelated']] as $ids) {
                        yield [$type, $remote, $nativeBackend, $ids];
                    }
                }
            }
        }
    }

    #[DataProvider('cases')]
    public function testFreshAdapterMatchesLegacyLogGate(string $type, bool $remoteAllowed, bool $nativeAdmin, array $ids): void {
        $data = new InMemoryConfig();
        $data->system['log_type'] = $type;
        $config = $data->mock($this);
        $mapper = $this->createMock(LogsGroupsMapper::class);
        $mapper->method('state')->willReturn(['groups' => ['logs'], 'version' => 1]);
        $manager = $this->createMock(IGroupManager::class);
        $manager->method('isAdmin')->with('alice')->willReturn($remoteAllowed && ($nativeAdmin || in_array('admin', $ids, true)));
        $manager->method('isInGroup')->willReturnCallback(static fn ($uid, $gid) => in_array($gid, $ids, true));
        $backend = $this->createMockForIntersectionOfInterfaces([GroupInterface::class, IIsAdminBackend::class]);
        $backend->method('implementsActions')->with(GroupInterface::IS_ADMIN)->willReturn(true);
        $backend->method('isAdmin')->with('alice')->willReturn($nativeAdmin);
        $manager->method('getBackends')->willReturn([$backend]);
        $remote = $this->createMock(IRemoteAddress::class);
        $remote->method('allowsAdminActions')->willReturn($remoteAllowed);
        $logs = new LogsAccess($config, $manager, $mapper, $this->createMock(ILockingProvider::class));
        $this->assertSame(['available' => $logs->available(), 'permitted' => $logs->permits('alice')],
            (new LogsRoleReader($logs, $manager, $remote))->read('alice', $ids));
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
        $reader = new LogsRoleReader($logs, $manager, $remote);
        $this->assertTrue($reader->read('alice', ['admin'])['permitted']);
        $allowed = false;
        $this->assertFalse($reader->read('alice', ['admin'])['permitted']);
        // Existing explicit logs roles are independent of the admin-address gate.
        $this->assertTrue($reader->read('alice', ['logs'])['permitted']);
        $this->assertFalse($reader->read('alice', [])['permitted']);
        $this->assertFalse($reader->read(null, ['admin', 'logs'])['permitted']);
    }
}
