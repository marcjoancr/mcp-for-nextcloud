<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Service\GrantMatrix;
use OCA\Mcp\Service\Policy\PolicyWriter;
use OCA\Mcp\Service\Policy\ScopeInactive;
use OCA\Mcp\Service\Scope\NativeResourceEnumerator;
use OCA\Mcp\Service\Scope\ScopeActivation;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Server;

/**
 * setResourceScope (issue #4, B2) on the real databases: the container's writer refuses calendar and tasks while their
 * activation barrier is closed (B6 opens it), leaving revision, events and tables as they were, and saves files,
 * opened by B5; behind an open barrier the scope
 * carries policy_rev = the committed revision, another session reads mode and items in one statement, the matrix
 * count is grouped by the real database and undo restores the absence of the mode. The open writer is built by hand
 * with a stand-in enumerator: native folders and calendars are not provisioned here.
 */
final class ResourceScopeWriterTest extends IntegrationTestCase {
    protected function tearDown(): void {
        $db = $this->official();
        foreach ([ResourceScopeMapper::MODES_TABLE, ResourceScopeMapper::ITEMS_TABLE] as $table) {
            $qb = $db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user(Fixtures::ALICE))))->executeStatement();
        }
        parent::tearDown();
    }

    private function eventCount(): int {
        $qb = $this->official()->getQueryBuilder();
        $result = $qb->select($qb->func()->count('*', 'n'))->from(PolicyEventsMapper::TABLE)->executeQuery();
        try {
            return (int)$result->fetchOne();
        } finally {
            $result->closeCursor();
        }
    }

    public function testTheContainerWiresTheScopeOpensFilesAndKeepsCalendarAndTasksClosed(): void {
        $writer = Server::get(PolicyWriter::class);
        foreach (['scopes' => ResourceScopeMapper::class, 'resources' => NativeResourceEnumerator::class, 'activation' => ScopeActivation::class] as $property => $class) {
            $this->assertInstanceOf($class, (new \ReflectionProperty(PolicyWriter::class, $property))->getValue($writer), $property);
        }
        $this->assertInstanceOf(ResourceScopeMapper::class, (new \ReflectionProperty(GrantMatrix::class, 'scopes'))->getValue(Server::get(GrantMatrix::class)));
        $this->assertSame(['files' => true, 'calendar' => false, 'tasks' => false], Server::get(ScopeActivation::class)->modules());

        $state = Server::get(PolicyStateMapper::class)->state();
        $events = $this->eventCount();
        foreach (['calendar', 'tasks'] as $module) {
            try {
                $writer->setResourceScope($state['revision'], 'admin', Fixtures::ALICE, $module, []);
                $this->fail("Closed barrier accepted for $module");
            } catch (ScopeInactive) {
            }
        }
        $this->assertSame($state, (new PolicyStateMapper($this->openIndependentConnection()))->state());
        $this->assertSame($events, $this->eventCount());
        $this->assertSame([], (new ResourceScopeMapper($this->openIndependentConnection()))->forUser(Fixtures::ALICE));

        // Restricted to nothing needs no native lookup, so the container's own enumerator accepts it.
        $saved = $writer->setResourceScope($state['revision'], 'admin', Fixtures::ALICE, 'files', []);
        $this->assertTrue($saved['changed']);
        $other = new ResourceScopeMapper($this->openIndependentConnection());
        $this->assertSame([], $other->forModule(Fixtures::ALICE, 'files')['items']);
        $this->assertNull($other->forModule(Fixtures::ALICE, 'calendar'));
    }

    public function testAnOpenWriterCommitsTheScopeWithItsRevisionAndUndoRestoresAbsence(): void {
        $resources = $this->createMock(NativeResourceEnumerator::class);
        $resources->method('inaccessible')->willReturn([]);
        $db = $this->official();
        $state = Server::get(PolicyStateMapper::class);
        $writer = new PolicyWriter($db, $state, Server::get(PolicyEventsMapper::class), Server::get(IConfig::class), Server::get(IUserConfig::class),
            Server::get(IAppConfig::class), Server::get(IUserManager::class), Server::get(ITimeFactory::class),
            Server::get(\OCA\Mcp\Db\AuthUsersMapper::class), Server::get(\OCA\Mcp\Db\RecheckMapper::class),
            Server::get(\OCA\Mcp\Service\Policy\PolicyEventProcessor::class), Server::get(\OCA\Mcp\Db\GroupGrantsMapper::class),
            Server::get(\OCA\Mcp\Db\GroupLifeMapper::class), Server::get(OAuthStore::class), Server::get(ResourceScopeMapper::class), $resources, new ScopeActivation(['files' => true, 'calendar' => true, 'tasks' => true]));
        $before = $state->state();
        $other = new ResourceScopeMapper($this->openIndependentConnection());

        $saved = $writer->setResourceScope($before['revision'], 'admin', Fixtures::ALICE, 'calendar', ['7', '3', '7']);

        $this->assertNotSame($before['revision'], $saved['revision']);
        $this->assertSame(['policyRev' => $saved['revision'], 'items' => ['3', '7']], $other->forModule(Fixtures::ALICE, 'calendar'));
        $this->assertSame([Fixtures::ALICE => ['calendar' => 2]], $other->counts([Fixtures::ALICE]));
        $this->assertSame($before['auth_epoch'], $state->state()['auth_epoch'], 'a scope moves no epoch');

        $writer->restoreReceipt((string)$saved['receiptId'], $saved['revision'], 'admin');
        $this->assertNull($other->forModule(Fixtures::ALICE, 'calendar'));
        $this->assertSame([Fixtures::ALICE => []], $other->counts([Fixtures::ALICE]));
    }
}
