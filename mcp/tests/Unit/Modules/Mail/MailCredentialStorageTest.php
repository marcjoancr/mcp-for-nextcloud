<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use Doctrine\DBAL\Schema\Schema;
use OCA\Mcp\Modules\Mail\Integration\MailCredentialStorage;
use OCP\{IDBConnection,IConfig};
use PHPUnit\Framework\TestCase;
final class MailCredentialStorageTest extends TestCase {
    public function testPrimaryEngineRevisionAndOneShotSchemaAreRequired():void {
        $s=new Schema();
        foreach(['mail_accounts','mail_mcp_auth_contexts','mail_mcp_auth_states','mcp_mail_setup','mcp_operations','mail_local_messages','mail_recipients','mail_attachments'] as $name)$s->createTable('custom_'.$name)->addOption('engine','InnoDB');
        $revision=$s->getTable('custom_mail_accounts')->addColumn('mcp_auth_revision','bigint',['notnull'=>true,'default'=>0]);
        $handle=$s->getTable('custom_mcp_mail_setup')->addColumn('native_context_id','string',['length'=>64,'notnull'=>false]);
        $contexts=$s->getTable('custom_mail_mcp_auth_contexts');
        foreach(['id'=>64,'source_key'=>64,'user_id'=>255,'kind'=>32,'phase'=>16] as $name=>$length)$contexts->addColumn($name,'string',['length'=>$length,'notnull'=>true]);
        $contexts->addColumn('target_id','bigint',['notnull'=>false]);$contexts->addColumn('intent_json','text',['notnull'=>true]);
        foreach(['created_at','expires_at'] as $name)$contexts->addColumn($name,'bigint',['notnull'=>true]);
        foreach(['activated_at','proof_revision','proof_at'] as $name)$contexts->addColumn($name,'bigint',['notnull'=>false]);
        $contexts->addColumn('proof_kind','string',['length'=>24,'notnull'=>false]);$contexts->addColumn('proof_protocols','smallint',['notnull'=>true,'default'=>0]);$contexts->addColumn('consumed_by','string',['length'=>128,'notnull'=>false]);
        $contexts->setPrimaryKey(['id']);$contexts->addUniqueIndex(['user_id','source_key'],'source_once');
        $states=$s->getTable('custom_mail_mcp_auth_states');
        foreach(['id','context_id'] as $name)$states->addColumn($name,'string',['length'=>64,'notnull'=>true]);
        foreach(['account_id','created_at','expires_at'] as $name)$states->addColumn($name,'bigint',['notnull'=>true]);
        $states->addColumn('attempt_id','string',['length'=>64,'notnull'=>false]);
        foreach(['used','completed'] as $name)$states->addColumn($name,'boolean',['notnull'=>true,'default'=>false]);
        $states->setPrimaryKey(['id']);
        $provider=IDBConnection::PLATFORM_MYSQL;$routing=[];
        $db=$this->createStub(IDBConnection::class);$db->method('createSchema')->willReturn($s);$db->method('getDatabaseProvider')->willReturnCallback(static function()use(&$provider){return $provider;});
        $config=$this->createStub(IConfig::class);$config->method('getSystemValueString')->willReturn('custom_');$config->method('getSystemValue')->willReturnCallback(static function($key,$default)use(&$routing){return $routing[$key]??$default;});
        $storage=new MailCredentialStorage($db,$config);self::assertTrue($storage->verified());
        foreach(['dbreplica','dbsharding'] as $key){$routing[$key]=['unsafe'];self::assertFalse($storage->verified(),$key);$routing=[];}
        foreach($s->getTables() as $table){$table->addOption('engine','MyISAM');self::assertFalse($storage->verified(),$table->getName());$table->addOption('engine','InnoDB');}
        $revision->setNotnull(false);self::assertFalse($storage->verified());$revision->setNotnull(true);
        $revision->setDefault(null);self::assertFalse($storage->verified());$revision->setDefault(0);
        $handle->setNotnull(true);self::assertFalse($storage->verified());$handle->setNotnull(false);
        $handle->setLength(32);self::assertFalse($storage->verified());$handle->setLength(64);
        $contexts->dropIndex('source_once');self::assertFalse($storage->verified());$contexts->addUniqueIndex(['user_id','source_key'],'source_once');
        $proofRevision=$contexts->getColumn('proof_revision');$proofRevision->setType(\Doctrine\DBAL\Types\Type::getType('smallint'));self::assertFalse($storage->verified(),'Truncated authorization revisions cannot be qualified');$proofRevision->setType(\Doctrine\DBAL\Types\Type::getType('bigint'));
        $stateId=$states->getColumn('id');$stateId->setLength(16);self::assertFalse($storage->verified(),'State identity must retain the full digest');$stateId->setLength(64);
        $states->dropColumn('attempt_id');self::assertFalse($storage->verified());
        $provider=IDBConnection::PLATFORM_SQLITE;self::assertFalse($storage->verified());
    }
}
