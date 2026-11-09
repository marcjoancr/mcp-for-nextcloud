<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use Doctrine\DBAL\Schema\Schema;
use OCA\Mcp\Modules\Mail\Integration\MailTagBoundary;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCP\{IDBConnection,IConfig};use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
final class MailTagBoundaryTest extends TestCase {
    public function testPhysicalConfiguredPrefixAndUniqueTransactionalTablesAreRequired():void {
        $schema=new Schema();$tags=$schema->createTable('custom_mail_tags');$tags->addColumn('user_id','string');$tags->addColumn('imap_label','string');$tags->addUniqueIndex(['user_id','imap_label'],'native_unique');$tags->addOption('engine','InnoDB');$ops=$schema->createTable('custom_mcp_operations');$ops->addOption('engine','InnoDB');
        $db=$this->createMock(IDBConnection::class);$db->method('createSchema')->willReturn($schema);$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);$config=$this->createMock(IConfig::class);$config->method('getSystemValue')->with('dbsharding',[])->willReturn([]);$config->method('getSystemValueString')->with('dbtableprefix','oc_')->willReturn('custom_');$b=new MailTagBoundary($this->createMock(IAppManager::class),$db,$config);
        $b->requireStorage();self::addToAssertionCount(1);$ops->addOption('engine','MyISAM');$failed=false;try{$b->requireStorage();}catch(BeforeEffectFailure){$failed=true;}self::assertTrue($failed,'Both journal and native tag tables must be transactional.');$ops->addOption('engine','InnoDB');$tags->dropIndex('native_unique');$this->expectException(BeforeEffectFailure::class);$b->requireStorage();
    }
}
