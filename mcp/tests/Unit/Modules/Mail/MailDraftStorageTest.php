<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use Doctrine\DBAL\Schema\Schema;
use OCA\Mcp\Modules\Mail\Integration\MailDraftStorage;
use OCP\{IDBConnection,IConfig,DB\IResult};
use PHPUnit\Framework\TestCase;
final class MailDraftStorageTest extends TestCase {
    public function testOnlyQualifiedPrimaryStorageWithPersistedDefaultDenyProvenanceIsAdmitted():void {
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Db/PgDirectConnection.php';
        $schema=new Schema();$table=$schema->createTable('custom_mail_local_messages');$table->addOption('engine','InnoDB');
        foreach(['mail_accounts','mail_recipients','mail_attachments'] as $name)$schema->createTable('custom_'.$name)->addOption('engine','InnoDB');
        $column=$table->addColumn('mcp_draft_only','boolean',['notnull'=>true,'default'=>false]);
        $provider=IDBConnection::PLATFORM_MYSQL;$routing=[];
        $db=$this->createMock(IDBConnection::class);$db->method('createSchema')->willReturn($schema);$db->method('getDatabaseProvider')->willReturnCallback(static function()use(&$provider){return $provider;});
        $db->method('executeQuery')->willReturnCallback(function():IResult {$r=$this->createMock(IResult::class);$r->method('fetch')->willReturn(['address'=>'127.0.0.1','port'=>5432]);return $r;});
        $endpoint='127.0.0.1';$config=$this->createMock(IConfig::class);$config->method('getSystemValueString')->willReturnCallback(static function($key,$default)use(&$endpoint){return $key==='dbhost'?$endpoint:'custom_';});$config->method('getSystemValue')->willReturnCallback(static function($key,$default)use(&$routing){return $routing[$key]??$default;});
        $guard=new MailDraftStorage($db,$config);self::assertTrue($guard->verified());$provider=IDBConnection::PLATFORM_POSTGRES;self::assertTrue($guard->verified(),'PostgreSQL tables are transactional without an InnoDB engine option.');$endpoint='127.0.0.1:6432';self::assertFalse($guard->verified(),'A PostgreSQL transaction-pool frontend is not admitted.');$endpoint='127.0.0.1';$provider=IDBConnection::PLATFORM_MYSQL;
        foreach(['dbreplica','dbsharding'] as $key){$routing[$key]=['unsafe'];self::assertFalse($guard->verified(),$key);$routing=[];}
        foreach(['mail_accounts','mail_local_messages','mail_recipients','mail_attachments'] as $name){$participant=$schema->getTable('custom_'.$name);$participant->addOption('engine','MyISAM');self::assertFalse($guard->verified(),$name);$participant->addOption('engine','InnoDB');}
        $column->setNotnull(false);self::assertFalse($guard->verified());$column->setNotnull(true);
        $column->setDefault(true);self::assertFalse($guard->verified());$column->setDefault(false);
        $table->dropColumn('mcp_draft_only');self::assertFalse($guard->verified());
        $provider=IDBConnection::PLATFORM_SQLITE;self::assertFalse($guard->verified());
    }
}
