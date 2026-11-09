<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use Doctrine\DBAL\Schema\Schema;
use OCA\Mcp\Modules\Mail\Integration\MailJournalStorage;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCP\{IDBConnection,IConfig};
use PHPUnit\Framework\TestCase;
final class MailJournalStorageTest extends TestCase {
    public function testAllPhysicalTablesAndPrimaryRoutingMustSupportTheSameAtomicTransaction():void {
        $schema=new Schema();foreach(['mcp_operations','mcp_mail_setup'] as $name)$schema->createTable('custom_'.$name)->addOption('engine','InnoDB');
        $db=$this->createMock(IDBConnection::class);$db->method('createSchema')->willReturn($schema);$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);
        $routing=[];$config=$this->createMock(IConfig::class);$config->method('getSystemValue')->willReturnCallback(static function(string $key,mixed $default)use(&$routing):mixed{return $routing[$key]??$default;});$config->method('getSystemValueString')->willReturn('custom_');
        $guard=new MailJournalStorage($db,$config);$guard->requireTables(['mcp_operations','mcp_mail_setup']);self::addToAssertionCount(1);
        foreach(['mcp_operations','mcp_mail_setup','dbreplica','dbsharding'] as $fault){
            $routing=[];foreach(['mcp_operations','mcp_mail_setup'] as $name)$schema->getTable('custom_'.$name)->addOption('engine','InnoDB');
            if(str_starts_with($fault,'db'))$routing[$fault]=['unsafe'];else $schema->getTable('custom_'.$fault)->addOption('engine','MyISAM');
            $refused=false;try{$guard->requireTables(['mcp_operations','mcp_mail_setup']);}catch(BeforeEffectFailure){$refused=true;}self::assertTrue($refused,$fault);
        }
    }
}
