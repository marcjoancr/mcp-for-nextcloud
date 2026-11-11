<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\Migration\{IOutput,SimpleMigrationStep};
use OCP\DB\ISchemaWrapper;
use PHPUnit\Framework\TestCase;
final class BoundedMailMetadataTest extends TestCase {
    private function fixture(array $topology=[],string $provider=IDBConnection::PLATFORM_MYSQL):array {
        $path=dirname(__DIR__,5).'/native/mail-guard/src/Db/BoundedMailMetadata.php';
        if(!is_file($path))self::fail('The native bounded Mail metadata reader has not been added yet.');
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Lifecycle/AccountVisibility.php';require_once dirname(__DIR__,5).'/native/mail-guard/src/Db/PgDirectConnection.php';require_once $path;
        $base=new class extends SimpleMigrationStep {
            public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
                $s=$schemaClosure();
                foreach(['mail_accounts'=>['user_id'=>'string','name'=>'string'],'mail_local_messages'=>['account_id'=>'integer','alias_id'=>'integer','type'=>'integer','subject'=>'string','failed'=>'boolean','send_at'=>'bigint','updated_at'=>'bigint'],'mail_mailboxes'=>['account_id'=>'integer','name'=>'string'],'mail_messages'=>['thread_root_id'=>'string','mailbox_id'=>'integer','subject'=>'string','sent_at'=>'bigint','flag_seen'=>'boolean','flag_flagged'=>'boolean']] as $name=>$columns){
                    $t=$s->createTable($name);$t->addColumn('id','integer',['autoincrement'=>true]);$t->setPrimaryKey(['id']);foreach($columns as $column=>$type)$t->addColumn($column,$type,['notnull'=>false]);
                }
                return $s;
            }
        };
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Migration/Version5125Date20261111020000.php';
        $sql=new SqliteDatabase($this,[$base,new \OCA\Mail\Migration\Version5125Date20261111020000()]);
        foreach([[1,'alice'],[2,'bob'],[3,'alice']] as [$id,$uid])$sql->dbal->insert('mail_accounts',['id'=>$id,'user_id'=>$uid]);
        foreach([[10,1],[11,2],[12,3]] as [$id,$account])$sql->dbal->insert('mail_mailboxes',['id'=>$id,'account_id'=>$account]);
        foreach([
            [1,1,0,str_repeat('x',300),null,null,100],
            [2,1,0,'second',false,10,200],
            [3,3,0,'third',true,null,300],
            [4,2,0,'foreign',false,null,400],
            [5,1,1,'not an outbox row',false,null,500],
        ] as [$id,$account,$type,$subject,$failed,$sendAt,$updatedAt])$sql->dbal->insert('mail_local_messages',['id'=>$id,'account_id'=>$account,'type'=>$type,'subject'=>$subject,'failed'=>$failed,'send_at'=>$sendAt,'updated_at'=>$updatedAt]);
        foreach([
            [100,10,str_repeat('c',300),101,1,0],
            [101,11,'foreign cache row',102,0,1],
            [102,12,str_repeat('a',255).'😀suffix',103,0,1],
        ] as [$id,$mailbox,$subject,$sentAt,$seen,$flagged])$sql->dbal->insert('mail_messages',['id'=>$id,'mailbox_id'=>$mailbox,'subject'=>$subject,'sent_at'=>$sentAt,'flag_seen'=>$seen,'flag_flagged'=>$flagged]);
        $db=$sql->connection();$db->method('getDatabaseProvider')->willReturn($provider);
        $settings=$topology;$config=$this->createStub(IConfig::class);$config->method('getSystemValue')->willReturnCallback(static fn(string $key,mixed $default)=>$settings[$key]??$default);
        return [$sql,new \OCA\Mail\Db\BoundedMailMetadata($db,$config)];
    }
    public function testTagVisibilityProvesAnOwnedAdmittedAssociationWithoutProjectingTagMetadata():void {
        [$sql,$reader]=$this->fixture();
        $sql->dbal->executeStatement('CREATE TABLE mail_tags (id INTEGER, user_id TEXT, display_name TEXT)');
        $sql->dbal->executeStatement('CREATE TABLE mail_message_tags (cached_message_id INTEGER, tag_id INTEGER)');
        foreach([[7,'alice'],[8,'bob']] as [$id,$owner])$sql->dbal->insert('mail_tags',['id'=>$id,'user_id'=>$owner,'display_name'=>'Private']);
        foreach([[100,7],[101,7],[102,8]] as [$message,$tag])$sql->dbal->insert('mail_message_tags',['cached_message_id'=>$message,'tag_id'=>$tag]);
        self::assertSame(['account_id'=>1,'mailbox_id'=>10],$reader->tagSource('alice',7,[],[10]));
        self::assertSame(['account_id'=>1,'mailbox_id'=>10],$reader->tagSource('alice',7,[1],[]));
        self::assertNull($reader->tagSource('alice',7,[],[12]));
        self::assertNull($reader->tagSource('alice',7,[2],[]));
        self::assertNull($reader->tagSource('alice',8,[3],[]));
    }
    public function testLocalMessageExistenceChecksOnlyOwnedSelectedAccountAndAlias():void {
        [$sql,$reader]=$this->fixture();
        self::assertTrue($reader->hasLocalMessages('alice',1,1));
        self::assertFalse($reader->hasLocalMessages('alice',2,0));
        self::assertFalse($reader->hasLocalMessages('alice',3,1));
        self::assertFalse($reader->hasLocalMessages('alice',1,1,77));
        $sql->dbal->update('mail_local_messages',['alias_id'=>77],['id'=>5]);
        self::assertTrue($reader->hasLocalMessages('alice',1,1,77));
    }
    public function testPickerInventoryIsOwnedAndSqlBoundedBeforeMaterialization():void {
        [$sql,$reader]=$this->fixture();
        $sql->dbal->executeStatement("UPDATE mail_accounts SET name = 'Account'");
        $sql->dbal->executeStatement("UPDATE mail_mailboxes SET name = 'INBOX'");
        $rows=$reader->resourceInventory('alice');
        self::assertSame(['account:1','account:3','mailbox:10','mailbox:12'],array_column($rows,'id'));
        self::assertSame(['id','name','path','kind','excluded'],array_keys($rows[0]));
        self::assertSame('Account',$rows[2]['path']);
        for($id=4;$id<=1002;$id++)$sql->dbal->insert('mail_accounts',['id'=>$id,'user_id'=>'alice','name'=>'Overflow']);
        $this->expectException(\RuntimeException::class);$reader->resourceInventory('alice');
    }
    public function testScopeFiltersBeforeOutboxPaginationAndIdentityProjectionHasNoPrivateText():void {
        [$sql,$reader]=$this->fixture();
        $page=$reader->outbox('alice',null,1,null,[1]);
        self::assertSame([2],array_column($page['items'],'id'));
        self::assertSame(2,$page['next_before_id']);
        self::assertSame([], $reader->outbox('alice',null,1,null,[])['items']);
        self::assertSame([['id'=>100,'account_id'=>1,'mailbox_id'=>10]],$reader->identities('alice','message',[100,101,999]));
        self::assertSame([['id'=>5,'account_id'=>1,'mailbox_id'=>0]],$reader->identities('alice','draft',[5]));
    }
    public function testScopedDraftAndThreadQueriesNeverCountHiddenSources():void {
        [$sql,$reader]=$this->fixture();$sql->dbal->executeStatement("UPDATE mail_messages SET thread_root_id = 'thread'");
        self::assertSame([100],$reader->threadIds('alice',1,'thread',[10]));
        self::assertSame([],$reader->threadIds('alice',1,'thread',[12]));
        self::assertSame(['ids'=>[5],'has_more'=>false],$reader->draftIds('alice',1,null,null,20,0,[1]));
        self::assertSame(['ids'=>[],'has_more'=>false],$reader->draftIds('alice',1,null,null,20,0,[]));
    }
    public function testOutboxReadsOnlyOwnedOutgoingRowsAndPaginatesWithBoundedSubjects():void {
        [$sql,$reader]=$this->fixture();
        $first=$reader->outbox('alice',null,1,null);
        self::assertSame([3],array_column($first['items'],'id'));self::assertSame(3,$first['next_before_id']);
        self::assertSame(['id','account_id','subject','subject_truncated','failed','send_at','updated_at'],array_keys($first['items'][0]));
        self::assertSame('third',$first['items'][0]['subject']);self::assertTrue($first['items'][0]['failed']);
        $second=$reader->outbox('alice',1,50,$first['next_before_id']);
        self::assertSame([2,1],array_column($second['items'],'id'));self::assertNull($second['next_before_id']);
        self::assertSame(str_repeat('x',256),$second['items'][1]['subject']);self::assertTrue($second['items'][1]['subject_truncated']);
        self::assertSame(100,$second['items'][1]['updated_at']);self::assertNull($second['items'][1]['send_at']);
        self::assertSame(2,$sql->queryCount);
    }
    public function testCachedMessageBatchReturnsOnlyRequestedOwnedScalarRows():void {
        [$sql,$reader]=$this->fixture();
        $rows=$reader->cachedMessages('alice',[102,100,101,999]);
        self::assertSame([100,102],array_column($rows,'id'));
        self::assertSame(['id','account_id','mailbox_id','subject','subject_truncated','sent_at','seen','flagged'],array_keys($rows[0]));
        self::assertSame([1,3],array_column($rows,'account_id'));
        self::assertSame([101,103],array_column($rows,'sent_at'));
        self::assertSame([true,false],array_column($rows,'seen'));
        self::assertSame([false,true],array_column($rows,'flagged'));
        self::assertSame(str_repeat('c',256),$rows[0]['subject']);self::assertTrue($rows[0]['subject_truncated']);
        self::assertSame(str_repeat('a',255).'😀',$rows[1]['subject']);self::assertTrue($rows[1]['subject_truncated']);
        self::assertSame(1,$sql->queryCount);
    }
    public function testInvalidBoundsAndUnqualifiedTopologyRefuseBeforeQueries():void {
        [$sql,$reader]=$this->fixture();
        foreach([0,51] as $limit){$refused=false;try{$reader->outbox('alice',null,$limit,null);}catch(\InvalidArgumentException){$refused=true;}self::assertTrue($refused);}
        foreach([[0,null], [null,0]] as [$account,$cursor]){$refused=false;try{$reader->outbox('alice',$account,1,$cursor);}catch(\InvalidArgumentException){$refused=true;}self::assertTrue($refused);}
        foreach([0,-1,'1'] as $id){$refused=false;try{$reader->cachedMessages('alice',[$id]);}catch(\InvalidArgumentException){$refused=true;}self::assertTrue($refused);}
        $refused=false;try{$reader->cachedMessages('alice',[1,1]);}catch(\InvalidArgumentException){$refused=true;}self::assertTrue($refused);
        $refused=false;try{$reader->cachedMessages('alice',range(1,51));}catch(\InvalidArgumentException){$refused=true;}self::assertTrue($refused);
        self::assertSame(0,$sql->queryCount);
        foreach([['dbreplica'=>[['host'=>'replica.invalid']]],['dbsharding'=>['shard'=>1]]] as $unsafe){
            [$otherSql,$other]=$this->fixture($unsafe);$refused=false;try{$other->outbox('alice',null,1,null);}catch(\RuntimeException){$refused=true;}self::assertTrue($refused);self::assertSame(0,$otherSql->queryCount);
        }
        [$otherSql,$unsupported]=$this->fixture([], 'sqlite');$refused=false;try{$unsupported->outbox('alice',null,1,null);}catch(\RuntimeException){$refused=true;}self::assertTrue($refused);self::assertSame(0,$otherSql->queryCount);
    }
    public function testEmptyCacheBatchReturnsWithoutIssuingSql():void {
        [$sql,$reader]=$this->fixture();self::assertSame([],$reader->cachedMessages('alice',[]));self::assertSame(0,$sql->queryCount);
    }

    public function testConfirmedOriginalAccountsAreExcludedBeforePaginationAndPreparedAccountsRemainVisible():void {
        [$sql,$reader]=$this->fixture();$sql->dbal->insert('mail_mcp_erasure',['id'=>'capture','user_id'=>'alice','state'=>'prepared','created_at'=>1]);$sql->dbal->insert('mail_mcp_erase_accounts',['erasure_id'=>'capture','account_id'=>3]);
        self::assertSame([3],array_column($reader->outbox('alice',null,1,null)['items'],'id'));
        $sql->dbal->update('mail_mcp_erasure',['state'=>'pending'],['id'=>'capture']);
        $page=$reader->outbox('alice',null,1,null);self::assertSame([2],array_column($page['items'],'id'));self::assertSame(2,$page['next_before_id']);
        self::assertSame([100],array_column($reader->cachedMessages('alice',[100,102]),'id'));
        self::assertSame([], $reader->outbox('alice',3,50,null)['items']);
    }
}
