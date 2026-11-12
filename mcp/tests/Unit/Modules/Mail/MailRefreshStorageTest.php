<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Schema\{Table,Schema};
use OCP\{IDBConnection,IConfig};
final class MailRefreshStorageTest extends TestCase {
    /** @dataProvider unsafe */
    public function testAllRefreshWritesRequireTransactionalStorage(string $unsafe,int $length):void {
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Db/ExactTagAssociations.php';require_once dirname(__DIR__,5).'/native/mail-guard/src/Sync/CacheSnapshot.php';
        $tables=[];foreach(['mail_messages','mail_message_tags','mail_mailboxes','mail_tags','mail_accounts','mail_recipients']as$name){$t=new Table('oc_'.$name);$t->addOption('engine',$name===$unsafe?'MyISAM':'InnoDB');$tables['oc_'.$name]=$t;}
        $tables['oc_mail_messages']->addColumn('id','bigint');foreach(['tag_cache_generation','tag_cache_profile']as$c)$tables['oc_mail_messages']->addColumn($c,'string');$links=$tables['oc_mail_message_tags'];$links->addColumn('tag_id','bigint');$links->addColumn('cached_message_id','bigint');$links->addUniqueIndex(['cached_message_id','tag_id']);$links->addForeignKeyConstraint('oc_mail_messages',['cached_message_id'],['id'],['onDelete'=>'CASCADE']);$tables['oc_mail_mailboxes']->addColumn('tag_cache_revision','bigint');$tables['oc_mail_mailboxes']->addColumn('refresh_snapshot','string',['length'=>$length,'notnull'=>false]);
        $schema=new Schema(array_values($tables));$db=$this->createMock(IDBConnection::class);$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);$db->method('createSchema')->willReturn($schema);$config=$this->createMock(IConfig::class);$config->method('getSystemValue')->willReturn([]);$config->method('getSystemValueString')->willReturn('oc_');$helper=new \OCA\Mail\Sync\CacheSnapshot($db,$config);
        if($unsafe===''&&$length===512){$helper->requireStorage();self::addToAssertionCount(1);return;}$this->expectException(\RuntimeException::class);$helper->requireStorage();
    }
    public static function unsafe():array {return [['',512],['mail_accounts',512],['mail_recipients',512],['',511]];}
}
