<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;
use OCP\DB\ISchemaWrapper;
use PHPUnit\Framework\TestCase;
final class MailExactTagAssociationsTest extends TestCase {
    private function fixture(): array {
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Migration/Version5125Date20261109000000.php';
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Db/TagProfile.php';
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Db/TagSnapshotVersion.php';
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Db/ExactTagAssociations.php';
        $base=new class extends SimpleMigrationStep {
            public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
                $s=$schemaClosure();foreach(['mail_accounts'=>['user_id','email','inbound_host','inbound_port','inbound_ssl_mode','inbound_user','auth_method','protocol'],'mail_mailboxes'=>['account_id','sync_new_token','sync_changed_token','sync_vanished_token'],'mail_messages'=>['mailbox_id','uid','message_id','flag_important','flag_seen'],'mail_tags'=>['user_id','imap_label'],'mail_message_tags'=>['imap_message_id','tag_id']]as$name=>$cols){$t=$s->createTable($name);$t->addColumn('id','integer',['autoincrement'=>true]);$t->setPrimaryKey(['id']);foreach($cols as$c)$t->addColumn($c,in_array($c,['account_id','mailbox_id','uid','tag_id','flag_important','flag_seen'],true)?'integer':'string',['notnull'=>false]);}return $s;
            }
        };
        $sql=new SqliteDatabase($this,[$base,new \OCA\Mail\Migration\Version5125Date20261109000000()]);$sql->dbal->executeStatement('PRAGMA foreign_keys=ON');
        foreach([[1,'alice'],[2,'bob']]as[$id,$uid])$sql->dbal->insert('mail_accounts',['id'=>$id,'user_id'=>$uid]);
        foreach([[1,1],[2,1],[3,2]]as[$id,$account])$sql->dbal->insert('mail_mailboxes',['id'=>$id,'account_id'=>$account,'sync_new_token'=>base64_encode('V42'),'sync_changed_token'=>base64_encode('V42'),'sync_vanished_token'=>base64_encode('V42')]);
        foreach([[1,1],[2,2],[3,3]]as[$id,$box])$sql->dbal->insert('mail_messages',['id'=>$id,'mailbox_id'=>$box,'uid'=>9,'message_id'=>'<duplicate>','tag_cache_generation'=>'42','tag_cache_profile'=>\OCA\Mail\Db\TagProfile::row(array_fill_keys(\OCA\Mail\Db\TagProfile::COLUMNS,null)),'flag_important'=>0,'flag_seen'=>1]);
        foreach([[10,'alice'],[11,'bob']]as[$id,$uid])$sql->dbal->insert('mail_tags',['id'=>$id,'user_id'=>$uid,'imap_label'=>'$reviewed']);
        $sql->dbal->insert('mail_message_tags',['imap_message_id'=>'<duplicate>','tag_id'=>10]);
        $db=$sql->connection();$db->method('getDatabaseProvider')->willReturn(\OCP\IDBConnection::PLATFORM_SQLITE);return [$sql,new \OCA\Mail\Db\ExactTagAssociations($db)];
    }
    public function testUnqualifiedNativeStorageProviderRefusesBeforeSchemaOrTransportWork():void {
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Db/ExactTagAssociations.php';$db=$this->createMock(\OCP\IDBConnection::class);$db->method('getDatabaseProvider')->willReturn(\OCP\IDBConnection::PLATFORM_SQLITE);$db->expects(self::never())->method('createSchema');$config=$this->createMock(\OCP\IConfig::class);$config->method('getSystemValue')->willReturnCallback(static fn($key,$default)=>$default);$this->expectException(\RuntimeException::class);(new \OCA\Mail\Db\ExactTagAssociations($db,$config))->requireStorage();
    }
    public function testStorageRequiresExactKeyCascadeAndAnUnreplicatedTransactionalDatabase():void {
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Db/TagProfile.php';
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Db/TagSnapshotVersion.php';
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Db/ExactTagAssociations.php';
        $schema=new \Doctrine\DBAL\Schema\Schema();$m=$schema->createTable('custom_mail_messages');$m->addColumn('id','bigint');$m->addColumn('tag_cache_generation','string',['notnull'=>false]);$m->addColumn('tag_cache_profile','string',['notnull'=>false]);$m->setPrimaryKey(['id']);$t=$schema->createTable('custom_mail_message_tags');$t->addColumn('cached_message_id','bigint',['notnull'=>false]);$t->addColumn('tag_id','integer');$t->addUniqueIndex(['cached_message_id','tag_id'],'exact');$t->addForeignKeyConstraint($m->getName(),['cached_message_id'],['id'],['onDelete'=>'CASCADE'],'exact_fk');
        foreach([$m,$t,$schema->createTable('custom_mail_tags'),$schema->createTable('custom_mail_mailboxes')]as$table)$table->addOption('engine','InnoDB');$schema->getTable('custom_mail_mailboxes')->addColumn('tag_cache_revision','bigint',['default'=>0]);
        $db=$this->createMock(\OCP\IDBConnection::class);$db->method('createSchema')->willReturn($schema);$db->method('getDatabaseProvider')->willReturn(\OCP\IDBConnection::PLATFORM_MYSQL);$settings=[];$config=$this->createMock(\OCP\IConfig::class);$config->method('getSystemValue')->willReturnCallback(static function($key,$default)use(&$settings){return $settings[$key]??$default;});$config->method('getSystemValueString')->willReturn('custom_');$native=new \OCA\Mail\Db\ExactTagAssociations($db,$config);$native->requireStorage();self::addToAssertionCount(1);
        $settings['dbreplica']=[['host'=>'replica.invalid']];$refused=false;try{$native->requireStorage();}catch(\RuntimeException){$refused=true;}self::assertTrue($refused);$settings=[];$t->removeForeignKey('exact_fk');$this->expectException(\RuntimeException::class);$native->requireStorage();
    }
    public function testOwnedBatchReadsStayBoundedAndLegacyAccountsRefuseNativeTagEdits():void {
        [$sql,$a]=$this->fixture();$queries=$sql->queryCount;self::assertSame([1,2],$a->verifiedIds('alice',[1,2,3]));self::assertSame(1,$sql->queryCount-$queries,'A message page must not add one query per message.');$a->requireAccountReady('alice',1);$sql->dbal->update('mail_messages',['tag_cache_generation'=>null],['id'=>1]);$this->expectException(\RuntimeException::class);$a->requireAccountReady('alice',1);
    }
    public function testImportantProjectionAndItsExactAssociationChangeTogetherWithoutOtherFlags():void {
        [$sql,$a]=$this->fixture();$sql->dbal->insert('mail_tags',['id'=>12,'user_id'=>'alice','imap_label'=>'$label1']);$a->set('alice',1,12,true);self::assertTrue($a->has('alice',1,12));self::assertSame(1,(int)$sql->dbal->fetchOne('SELECT flag_important FROM mail_messages WHERE id=1'));self::assertSame(1,(int)$sql->dbal->fetchOne('SELECT flag_seen FROM mail_messages WHERE id=1'));$a->set('alice',1,12,false);self::assertFalse($a->has('alice',1,12));self::assertSame(0,(int)$sql->dbal->fetchOne('SELECT flag_important FROM mail_messages WHERE id=1'));
    }
    public function testLegacyRowsAreQuarantinedAndDuplicateHeadersHaveIndependentAssociations():void {
        [$sql,$a]=$this->fixture();self::assertFalse($a->has('alice',1,10));self::assertFalse($a->has('alice',2,10));
        $a->set('alice',1,10,true);$a->set('alice',1,10,true);self::assertTrue($a->has('alice',1,10));self::assertFalse($a->has('alice',2,10));
        self::assertSame(2,(int)$sql->dbal->fetchOne('SELECT COUNT(*) FROM mail_message_tags'));
        $a->set('alice',2,10,true);$a->set('alice',1,10,false);self::assertFalse($a->has('alice',1,10));self::assertTrue($a->has('alice',2,10));
        $sql->dbal->delete('mail_messages',['id'=>2]);self::assertSame(1,(int)$sql->dbal->fetchOne('SELECT COUNT(*) FROM mail_message_tags'));self::assertNull($sql->rows('mail_message_tags')[0]['cached_message_id']);
    }
    public function testForeignOrUnprovenCacheCannotBeMutated():void {
        [$sql,$a]=$this->fixture();foreach([['bob',1,11],['alice',3,10],['alice',1,11]]as$args){$refused=false;try{$a->set(...[...$args,true]);}catch(\RuntimeException){$refused=true;}self::assertTrue($refused);}
        $sql->dbal->update('mail_messages',['tag_cache_generation'=>null],['id'=>1]);self::assertFalse($a->verified('alice',1));$this->expectException(\RuntimeException::class);$a->set('alice',1,10,true);
    }
    public function testANativeDtoCannotPointItsCachedIdAtAnotherMailboxOrUid():void {
        [$sql,$a]=$this->fixture();$a->assertIdentity('alice',1,1,9,'42');self::addToAssertionCount(1);$this->expectException(\RuntimeException::class);$a->assertIdentity('alice',1,2,9,'42');
    }
    public function testOAuthUsesTheNativeEmailPrincipalInItsCacheIdentity():void {
        [$sql,$a]=$this->fixture();$sql->dbal->update('mail_accounts',['auth_method'=>'xoauth2','email'=>'alice@example.test'],['id'=>1]);$row=$sql->dbal->fetchAssociative('SELECT * FROM mail_accounts WHERE id=1');$sql->dbal->update('mail_messages',['tag_cache_profile'=>\OCA\Mail\Db\TagProfile::row($row)],['id'=>1]);self::assertTrue($a->verified('alice',1));$sql->dbal->update('mail_accounts',['email'=>'other@example.test'],['id'=>1]);self::assertFalse($a->verified('alice',1),'The native XOAUTH2 token authenticates account.email, not only inbound_user.');
    }
    public function testChangingTheInboundIdentityInvalidatesTheCachedTagTarget():void {
        [$sql,$a]=$this->fixture();self::assertTrue($a->verified('alice',1));$sql->dbal->update('mail_accounts',['inbound_host'=>'other.example.test'],['id'=>1]);self::assertFalse($a->verified('alice',1));
    }
    public function testAnOlderSyncCannotOverwriteATagWriteOrAdoptAnExternalRevision():void {
        [$sql,$a]=$this->fixture();$snapshot=$a->capture('alice',1,'42');$a->set('alice',1,10,true);
        $db=$sql->connection();$db->beginTransaction();$refused=false;try{$a->claim($snapshot);}catch(\RuntimeException){$refused=true;}finally{$db->rollBack();}
        self::assertTrue($refused);self::assertTrue($a->has('alice',1,10));
        $fresh=$a->capture('alice',1,'42');$db->beginTransaction();$a->claim($fresh);$db->commit();$fresh->committed();
        $db->beginTransaction();$a->claim($fresh);$db->commit();$fresh->committed();self::assertSame(3,$fresh->expected());
        $a->set('alice',1,10,false);$db->beginTransaction();$refused=false;try{$a->claim($fresh);}catch(\RuntimeException){$refused=true;}finally{$db->rollBack();}self::assertTrue($refused);self::assertFalse($a->has('alice',1,10));
    }
    private function replace($sql,$a,string $generation,array $tags):int {
        $v=$a->capture('alice',1,$generation);$db=$sql->connection();$db->beginTransaction();try{$a->claim($v);$id=$a->replace('alice',1,9,null,$generation,$tags,false,$v);$db->commit();$v->committed();return $id;}catch(\Throwable $e){$db->rollBack();$v->aborted();throw $e;}
    }
    public function testFreshSnapshotResolvesOnlyOwnedMailboxUidAndNeverHeader():void {
        [$sql,$a]=$this->fixture();$sql->dbal->update('mail_messages',['tag_cache_generation'=>null],['id'=>1]);
        self::assertSame(1,$this->replace($sql,$a,'42',[10]));self::assertTrue($a->has('alice',1,10));self::assertFalse($a->has('alice',2,10));self::assertTrue($a->verified('alice',1));
        $refused=false;try{$this->replace($sql,$a,'43',[]);}catch(\RuntimeException){$refused=true;}self::assertTrue($refused);self::assertTrue($a->has('alice',1,10));
        $sql->dbal->update('mail_mailboxes',['sync_changed_token'=>base64_encode('V43')],['id'=>1]);self::assertFalse($a->verified('alice',1));
    }
}
