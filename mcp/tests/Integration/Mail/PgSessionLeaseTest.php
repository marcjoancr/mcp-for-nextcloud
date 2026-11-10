<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\{IDBConnection,Server,App\IAppManager};
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class PgSessionLeaseTest extends IntegrationTestCase {
    public function testLocksAreSessionScopedNonRecursiveAndSurviveTransactionRollback():void {
        if($this->cellDatabase()!=='pgsql')self::markTestSkipped('This contract runs in the PostgreSQL fixture.');
        Server::get(IAppManager::class)->loadApp('mail');
        $first=$this->openIndependentConnection();$second=$this->openIndependentConnection();
        self::assertSame(IDBConnection::PLATFORM_POSTGRES,$first->getDatabaseProvider());
        $lease=\OCA\Mail\Db\PgSessionLease::acquire($first,'oc_','integration','owner-a');
        try {
            $lease->assertOwned();self::addToAssertionCount(1);
            try {\OCA\Mail\Db\PgSessionLease::acquire($first,'oc_','integration','owner-a');self::fail('Recursive acquisition must fail.');}catch(\RuntimeException){}
            try {\OCA\Mail\Db\PgSessionLease::acquire($second,'oc_','integration','owner-a');self::fail('A second backend must not acquire the same owner.');}catch(\RuntimeException){}
            $first->beginTransaction();$first->rollBack();$lease->assertOwned();self::addToAssertionCount(1);
            $other=\OCA\Mail\Db\PgSessionLease::acquire($second,'oc_','integration','owner-b');$other->assertOwned();$other->release();
        } finally {$lease->release();}
        $successor=\OCA\Mail\Db\PgSessionLease::acquire($second,'oc_','integration','owner-a');$successor->assertOwned();$successor->release();
    }
    public function testClosedSessionLeaseCannotReleaseSuccessor():void {
        if($this->cellDatabase()!=='pgsql')self::markTestSkipped('This contract runs in the PostgreSQL fixture.');
        Server::get(IAppManager::class)->loadApp('mail');
        $first=$this->openIndependentConnection();$second=$this->openIndependentConnection();
        $stale=\OCA\Mail\Db\PgSessionLease::acquire($first,'oc_','integration','closed-session-owner');$first->close();
        $successor=\OCA\Mail\Db\PgSessionLease::acquire($second,'oc_','integration','closed-session-owner');
        $stale->release();$successor->assertOwned();self::addToAssertionCount(1);$successor->release();
    }
}
