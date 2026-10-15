<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\PolicyStateSeeder;
use OCA\Mcp\Migration\PolicyStateSeedStep;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\Policy\PolicyConflict;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** T1/T2 on the real core: official config writes, two independent sessions, reused worker caches. */
final class PolicyTransactionTest extends IntegrationTestCase {
    private const SET_KEY = 'grant_notes_edit';
    private const DELETE_KEY = 'grant_deck_move';
    private string $gid = '';

    protected function tearDown(): void {
        parent::tearDown();
        $config = Server::get(IConfig::class);
        foreach ([self::SET_KEY, self::DELETE_KEY] as $key) {
            $config->deleteUserValue(Fixtures::ALICE, 'mcp', $key);
        }
        $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY);
        Server::get(IUserConfig::class)->clearCache(Fixtures::ALICE, false);
        Server::get(IAppConfig::class)->clearCache(false);
        if ($this->gid !== '') {
            $qb = $this->official()->getQueryBuilder();
            $qb->delete('mcp_group_grants')->where($qb->expr()->eq('group_key', $qb->createNamedParameter(hash('sha256', "group\0" . $this->gid))))->executeStatement();
        }
    }

    public static function failurePoints(): array {
        return array_map(static fn ($point): array => [$point], ['set', 'delete', 'app', 'group', 'cas', 'none']);
    }

    #[DataProvider('failurePoints')]
    public function testAtomicityAndCacheRecoveryAfterEveryWrite(string $failure): void {
        $db = $this->official();
        $second = $this->openIndependentConnection();
        $this->assertNotSame($this->sessionId($db), $this->sessionId($second));
        $config = Server::get(IConfig::class);
        $users = Server::get(IUserConfig::class);
        $apps = Server::get(IAppConfig::class);
        // Verify the actual core dependency instead of assuming the container shares the connection.
        $this->assertSame($db, (new \ReflectionProperty($users, 'connection'))->getValue($users));
        $this->assertSame($db, (new \ReflectionProperty($apps, 'connection'))->getValue($apps));
        $config->setUserValue(Fixtures::ALICE, 'mcp', self::SET_KEY, '0');
        $config->setUserValue(Fixtures::ALICE, 'mcp', self::DELETE_KEY, '1');
        $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, '0');
        $users->clearCache(Fixtures::ALICE, false);
        $apps->clearCache(false);
        $this->assertSame('0', $config->getUserValue(Fixtures::ALICE, 'mcp', self::SET_KEY));
        $this->assertSame('1', $config->getUserValue(Fixtures::ALICE, 'mcp', self::DELETE_KEY));
        $typeBefore = $users->getValueType(Fixtures::ALICE, 'mcp', self::SET_KEY);
        $this->gid = 'mcp-it-policy-' . bin2hex(random_bytes(8));
        $this->assertSame($db, (new \ReflectionProperty(Server::get(OAuthStore::class), 'db'))->getValue(Server::get(OAuthStore::class)));
        $state = new PolicyStateMapper($db);
        $before = $state->state();

        // Test-only core clones bind to the independent session. All reads still use official config APIs;
        // AllConfig itself resolves services statically and therefore cannot represent a second session.
        $otherUsers = clone $users;
        (new \ReflectionProperty($otherUsers, 'connection'))->setValue($otherUsers, $second);
        $otherApps = clone $apps;
        (new \ReflectionProperty($otherApps, 'connection'))->setValue($otherApps, $second);
        $observeOld = function () use ($otherUsers, $otherApps, $second, $before): void {
            $otherUsers->clearCache(Fixtures::ALICE, false);
            $otherApps->clearCache(false);
            $this->assertSame([Fixtures::ALICE => '0'], $otherUsers->getValuesByUsers('mcp', self::SET_KEY, null, [Fixtures::ALICE]));
            $this->assertSame([Fixtures::ALICE => '1'], $otherUsers->getValuesByUsers('mcp', self::DELETE_KEY, null, [Fixtures::ALICE]));
            $this->assertSame('0', $otherApps->getValueString('mcp', GrantPolicy::SERVICE_KEY));
            $this->assertSame([['groupId' => $this->gid, 'grants' => []]], (new GroupGrantsMapper($second))->forGroups([$this->gid]));
            $this->assertSame($before, (new PolicyStateMapper($second))->state());
        };
        $after = function (string $point) use ($observeOld, $failure): void {
            $observeOld();
            if ($failure === $point) {
                throw new RuntimeException('Injected after ' . $point);
            }
        };
        $mapper = new class($db, $after) extends PolicyStateMapper {
            public function __construct(IDBConnection $db, private \Closure $after) { parent::__construct($db); }
            public function compareAndSet(string $expected): void {
                parent::compareAndSet($expected);
                ($this->after)('cas');
            }
        };
        $writer = new PolicyWriter($db, $mapper, $config, $users, $apps, Server::get(IUserManager::class), Server::get(OAuthStore::class));
        try {
            $result = $writer->runAdmin($before['revision'], [Fixtures::ALICE], function () use ($db): void {
                $this->assertFalse($db->inTransaction());
                $this->assertNotNull(Server::get(IUserManager::class)->get(Fixtures::ALICE));
            }, function () use ($config, $db, $after): bool {
                $config->setUserValue(Fixtures::ALICE, 'mcp', self::SET_KEY, '1');
                $after('set');
                $config->deleteUserValue(Fixtures::ALICE, 'mcp', self::DELETE_KEY);
                $after('delete');
                $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, '1');
                $after('app');
                $qb = $db->getQueryBuilder();
                $qb->insert('mcp_group_grants')
                    ->setValue('group_key', $qb->createNamedParameter(hash('sha256', "group\0" . $this->gid)))
                    ->setValue('group_id', $qb->createNamedParameter($this->gid))
                    ->setValue('module', $qb->createNamedParameter('notes'))
                    ->setValue('operation', $qb->createNamedParameter('edit'))->executeStatement();
                $after('group');
                return true;
            });
            $this->assertSame('none', $failure);
            $this->assertTrue($result['changed']);
        } catch (RuntimeException $e) {
            if (!str_starts_with($e->getMessage(), 'Injected after ')) {
                throw $e;
            }
            $this->assertNotSame('none', $failure);
        }
        $this->assertFalse($db->inTransaction());
        if ($failure !== 'none') {
            $observeOld();
            $this->assertSame($before, $state->state());
            // The exact IConfig/IUserConfig/IAppConfig objects used before and during the failed transaction.
            $this->assertSame('0', $config->getUserValue(Fixtures::ALICE, 'mcp', self::SET_KEY));
            $this->assertSame('1', $config->getUserValue(Fixtures::ALICE, 'mcp', self::DELETE_KEY));
            $this->assertTrue($users->hasKey(Fixtures::ALICE, 'mcp', self::DELETE_KEY));
            $this->assertSame('0', $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY));
        } else {
            $otherUsers->clearCache(Fixtures::ALICE, false);
            $otherApps->clearCache(false);
            $this->assertSame([Fixtures::ALICE => '1'], $otherUsers->getValuesByUsers('mcp', self::SET_KEY, null, [Fixtures::ALICE]));
            $this->assertSame([], $otherUsers->getValuesByUsers('mcp', self::DELETE_KEY, null, [Fixtures::ALICE]));
            $this->assertSame('1', $otherApps->getValueString('mcp', GrantPolicy::SERVICE_KEY));
            $this->assertNotSame($before['revision'], (new PolicyStateMapper($second))->state()['revision']);
            $this->assertSame([['groupId' => $this->gid, 'grants' => ['notes' => ['edit']]]], (new GroupGrantsMapper($second))->forGroups([$this->gid]));
            $this->assertSame('1', $config->getUserValue(Fixtures::ALICE, 'mcp', self::SET_KEY));
            $this->assertFalse($users->hasKey(Fixtures::ALICE, 'mcp', self::DELETE_KEY));
        }
        $this->assertSame($typeBefore, $users->getValueType(Fixtures::ALICE, 'mcp', self::SET_KEY));
    }

    public function testTwoWritersRejectStaleRevisionAndRepeatedSeedPreservesIt(): void {
        $state = Server::get(PolicyStateMapper::class);
        $writer = Server::get(PolicyWriter::class);
        $revision = $state->state()['revision'];
        $writer->runAdmin($revision, [], fn () => null, fn () => true);
        $after = $state->state();
        (new Version001100Date20261015000000($this->official()))->postSchemaChange($this->createMock(IOutput::class), fn () => null, []);
        // The step the server runs on install and after a migration, over the same live row.
        $step = new PolicyStateSeedStep(new PolicyStateSeeder($this->official()));
        $step->run($this->createMock(IOutput::class));
        $this->assertSame($after, $state->state());
        try {
            $writer->runAdmin($revision, [], fn () => null, fn () => $this->fail('Stale writer applied'));
            $this->fail('Stale writer accepted');
        } catch (PolicyConflict) {
        }
        $this->assertSame($after, (new PolicyStateMapper($this->openIndependentConnection()))->state());
    }
}
