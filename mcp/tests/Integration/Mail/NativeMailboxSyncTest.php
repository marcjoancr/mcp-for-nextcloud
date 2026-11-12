<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\{Server,IDBConnection,IConfig,IUserSession,IUserManager};
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class NativeMailboxSyncTest extends IntegrationTestCase {
    protected function setUp():void {
        parent::setUp();
        if(getenv('MCP_IT_WITH_MAIL')!=='1'||getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Native guard fixture only.');
        Server::get(IUserSession::class)->setUser(Server::get(IUserManager::class)->get(MailActor::ALICE));
        Server::get(\OCP\App\IAppManager::class)->loadApp('mail');
    }
    private function account():object {return Server::get('OCA\\Mail\\Service\\AccountService')->findByUserId(MailActor::ALICE)[0];}
    /** Only the provider-read seam is instrumented; real mapper/transaction/lease code runs. */
    private function sync(?\Closure $afterRead=null,?\Closure $afterAccountWrite=null):object {
        $folders=Server::get('OCA\\Mail\\IMAP\\FolderMapper');
        if($afterRead!==null){$real=$folders;$folders=$this->getMockBuilder(\OCA\Mail\IMAP\FolderMapper::class)->setConstructorArgs([Server::get(\Psr\Log\LoggerInterface::class)])->onlyMethods(['getFolders'])->getMock();$folders->method('getFolders')->willReturnCallback(static function(...$args)use($real,$afterRead){$result=$real->getFolders(...$args);$afterRead();return $result;});}
        $accounts=Server::get('OCA\\Mail\\Db\\MailAccountMapper');
        if($afterAccountWrite!==null){$real=$accounts;$accounts=$this->getMockBuilder(\OCA\Mail\Db\MailAccountMapper::class)->setConstructorArgs([$this->official()])->onlyMethods(['update','find'])->getMock();$accounts->method('find')->willReturnCallback(static fn(...$args)=>$real->find(...$args));$accounts->method('update')->willReturnCallback(static function($entity)use($real,$afterAccountWrite){$result=$real->update($entity);$afterAccountWrite();return $result;});}
        return new \OCA\Mail\IMAP\MailboxSync(Server::get('OCA\\Mail\\Db\\MailboxMapper'),$folders,$accounts,Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory'),Server::get(\OCP\AppFramework\Utility\ITimeFactory::class),Server::get(\OCP\EventDispatcher\IEventDispatcher::class),$this->official());
    }
    private function synchronize(object $sync,object $account):void {$sync->sync($account,Server::get(\Psr\Log\LoggerInterface::class),true);}
    private function snapshot(object $a):array {
        $db=$this->official();$q=$db->getQueryBuilder();$r=$q->select('last_mailbox_sync','personal_namespace','mcp_auth_revision')->from('mail_accounts')->where($q->expr()->eq('id',$q->createNamedParameter($a->getId())))->executeQuery();try{$account=$r->fetch();}finally{$r->closeCursor();}
        $q=$db->getQueryBuilder();$r=$q->select('*')->from('mail_mailboxes')->where($q->expr()->eq('account_id',$q->createNamedParameter($a->getId())))->orderBy('id')->executeQuery();try{$boxes=$r->fetchAll();}finally{$r->closeCursor();}return [$account,$boxes];
    }
    private function refused(\Closure $call):bool {try{$call();return false;}catch(\OCA\Mail\Exception\DraftAdmissionFailure|\RuntimeException){return true;}}
    public function testNativeUiControllerCreatesAndResynchronizesMailbox():void {
        $a=$this->account();$controller=Server::get('OCA\\Mail\\Controller\\MailboxesController');$manager=Server::get('OCA\\Mail\\Service\\MailManager');$name='mcp-sync-ui-'.bin2hex(random_bytes(8));$box=null;$before=MailNativeMutationActor::safety(MailActor::ALICE);
        try{$response=$controller->create($a->getId(),$name);self::assertSame(200,$response->getStatus());$box=$response->getData();self::assertSame($name,$box->getName());self::assertSame($a->getId(),$box->getAccountId());
            $response=$controller->index($a->getId(),true);self::assertSame(200,$response->getStatus());$ids=array_map(static fn($b)=>$b->getId(),$response->getData()['mailboxes']);self::assertContains($box->getId(),$ids);self::assertFalse($this->official()->inTransaction());self::assertSame($before,MailNativeMutationActor::safety(MailActor::ALICE));
        }finally{if($box!==null)$manager->deleteMailbox($a,$box);}
    }
    public function testNativeClientCreationCanPersistFreshCredentialsBeforeProviderReads():void {
        $a=$this->account();$mapper=Server::get('OCA\\Mail\\Db\\MailAccountMapper');$old=$a->getMailAccount()->getName();$revision=$a->getMailAccount()->getMcpAuthRevision();$fired=false;
        $events=Server::get(\OCP\EventDispatcher\IEventDispatcher::class);$event=\OCA\Mail\Events\BeforeImapClientCreated::class;
        // The pinned OAuth refresh listener persists this same Account at this exact pre-client event.
        // A nonsecret identity field exercises its revision transition without contacting an OAuth provider.
        $listener=function($e)use($a,$mapper,$old,&$fired):void {if($fired||$e->getAccount()!==$a)return;$fired=true;$a->getMailAccount()->setName($old.' refreshed');$mapper->update($a->getMailAccount());};
        $events->addListener($event,$listener);
        try{$this->synchronize($this->sync(),$a);self::assertTrue($fired);self::assertGreaterThan($revision,$a->getMailAccount()->getMcpAuthRevision());self::assertSame($a->getMailAccount()->getMcpAuthRevision(),$this->account()->getMailAccount()->getMcpAuthRevision());self::assertFalse($this->official()->inTransaction());}
        finally{$events->removeListener($event,$listener);$fresh=$mapper->find(MailActor::ALICE,$a->getId());$fresh->setName($old);$mapper->update($fresh);}
    }
    public function testNativeSyncHoldsOwnerBeforeProviderReadAndNestedAccountWrite():void {
        $a=$this->account();$other=$this->openIndependentConnection();$prefix=Server::get(IConfig::class)->getSystemValueString('dbtableprefix','oc_');$seen=false;$blocked=false;$contender=[];
        self::assertNotSame($this->sessionId($this->official()),$this->sessionId($other));
        $sync=$this->sync(function()use(&$seen,&$blocked,&$contender,$a,$other,$prefix){$seen=true;self::assertFalse($this->official()->inTransaction());$lease=null;try{$lease=\OCA\Mail\Drafts\DraftOwnerLease::acquire($other,$prefix,MailActor::ALICE,Server::get(IConfig::class));}catch(\RuntimeException){$blocked=true;}finally{$lease?->release();}$contender=MailActor::run(MailActor::ALICE,'mailbox-sync-contender',['account_id'=>$a->getId()]);});
        $revision=$a->getMailAccount()->getMcpAuthRevision();$this->synchronize($sync,$a);
        self::assertSame(['written'=>false,'unchanged'=>true],$contender);self::assertSame(['written'=>true,'unchanged'=>false],MailActor::run(MailActor::ALICE,'mailbox-sync-contender',['account_id'=>$a->getId()]));
        self::assertTrue($seen);self::assertTrue($blocked,'A different physical session must be blocked during provider reads.');self::assertFalse($this->official()->inTransaction());
        $fresh=$this->account();self::assertGreaterThan(0,$fresh->getMailAccount()->getLastMailboxSync());self::assertSame($revision,$fresh->getMailAccount()->getMcpAuthRevision());
        $lease=\OCA\Mail\Drafts\DraftOwnerLease::acquire($other,$prefix,MailActor::ALICE,Server::get(IConfig::class));$lease->assertOwned();$lease->release();
        \OCA\Mail\Drafts\DraftCoordinator::forDatabase($this->official())->runOwner(MailActor::ALICE,fn()=>$this->synchronize($this->sync(),$this->account()));
        self::assertFalse($this->official()->inTransaction());
    }
    public function testForeignOuterTransactionRefusesBeforeProviderReadEvenWithOwnerScope():void {
        $a=$this->account();$read=false;$sync=$this->sync(function()use(&$read){$read=true;});$db=$this->official();$before=$this->snapshot($a);
        $guard=\OCA\Mail\Drafts\DraftCoordinator::forDatabase($db);
        foreach([false,true] as $owned){$effect=function()use($db,$sync,$a,&$read){$db->beginTransaction();try{self::assertTrue($this->refused(fn()=>$this->synchronize($sync,$a)));self::assertTrue($db->inTransaction());self::assertFalse($read);}finally{$db->rollBack();}};if($owned)$guard->runOwner(MailActor::ALICE,$effect);else $effect();}
        self::assertSame($before,$this->snapshot($a));
    }
    public function testStaleCredentialRevisionRefusesBeforeProviderRead():void {
        $a=$this->account();$mapper=Server::get('OCA\\Mail\\Db\\MailAccountMapper');$fresh=$mapper->find(MailActor::ALICE,$a->getId());$old=$fresh->getName();$fresh->setName($old.' sync revision probe');$mapper->update($fresh);$read=false;
        try{$before=$this->snapshot($a);self::assertTrue($this->refused(fn()=>$this->synchronize($this->sync(function()use(&$read){$read=true;}),$a)));self::assertFalse($read);self::assertSame($before,$this->snapshot($a));}
        finally{$fresh=$mapper->find(MailActor::ALICE,$a->getId());$fresh->setName($old);$mapper->update($fresh);}
    }
    public function testChangedProfileAfterProviderReadCannotPersistOldFolderResults():void {
        $a=$this->account();$mapper=Server::get('OCA\\Mail\\Db\\MailAccountMapper');$old=$a->getMailAccount()->getProtocol();$afterChange=null;$fired=false;
        $sync=$this->sync(function()use($a,$mapper,$old,&$afterChange,&$fired){$fired=true;$fresh=$mapper->find(MailActor::ALICE,$a->getId());$fresh->setProtocol($old==='imap'?'pop3':'imap');$mapper->update($fresh);$afterChange=$this->snapshot($a);});
        try{self::assertTrue($this->refused(fn()=>$this->synchronize($sync,$a)));self::assertTrue($fired);self::assertSame($afterChange,$this->snapshot($a));self::assertFalse($this->official()->inTransaction());}
        finally{$fresh=$mapper->find(MailActor::ALICE,$a->getId());$fresh->setProtocol($old);$mapper->update($fresh);}
    }
    public function testCredentialRevisionAfterProviderReadCannotPersistOldFolderResults():void {
        $a=$this->account();$mapper=Server::get('OCA\\Mail\\Db\\MailAccountMapper');$old=$a->getMailAccount()->getName();$afterChange=null;$fired=false;
        $sync=$this->sync(function()use($a,$mapper,$old,&$afterChange,&$fired){$fired=true;$a->getMailAccount()->setName($old.' changed after read');$mapper->update($a->getMailAccount());$afterChange=$this->snapshot($a);});
        try{self::assertTrue($this->refused(fn()=>$this->synchronize($sync,$a)));self::assertTrue($fired);self::assertSame($afterChange,$this->snapshot($a));self::assertFalse($this->official()->inTransaction());}
        finally{$fresh=$mapper->find(MailActor::ALICE,$a->getId());$fresh->setName($old);$mapper->update($fresh);}
    }
    public function testChangedInboundProfileOnUnpersistedObjectRefusesBeforeProviderRead():void {
        $a=$this->account();$before=$this->snapshot($a);$a->getMailAccount()->setInboundUser($a->getMailAccount()->getInboundUser().'-stale');$read=false;
        self::assertTrue($this->refused(fn()=>$this->synchronize($this->sync(function()use(&$read){$read=true;}),$a)));self::assertFalse($read);self::assertSame($before,$this->snapshot($a));
    }
    public function testClosedPhysicalSessionAfterProviderReadRefusesWithoutSqlEffects():void {
        $a=$this->account();$before=$this->snapshot($a);$fired=false;
        $sync=$this->sync(function()use(&$fired){$fired=true;$this->official()->close();});
        self::assertTrue($this->refused(fn()=>$this->synchronize($sync,$a)));self::assertTrue($fired);self::assertSame($before,$this->snapshot($a));self::assertFalse($this->official()->inTransaction());
    }
    private function setSyncClock(object $a,int $time):void {$mapper=Server::get('OCA\\Mail\\Db\\MailAccountMapper');$fresh=$mapper->find(MailActor::ALICE,$a->getId());$fresh->setLastMailboxSync($time);$mapper->update($fresh);}
    public function testLostOwnerLockBeforeCommitRollsBackNativeCacheOnLiveConnection():void {
        if($this->cellDatabase()!=='pgsql')self::markTestSkipped('PostgreSQL physical lease fault.');
        $a=$this->account();$oldClock=$a->getMailAccount()->getLastMailboxSync();$this->setSyncClock($a,0);$before=$this->snapshot($a);$pid=$this->sessionId($this->official());$fired=false;$inTransaction=false;
        $sync=$this->sync(null,function()use(&$fired,&$inTransaction){$fired=true;$inTransaction=$this->official()->inTransaction();$this->official()->executeQuery('SELECT pg_advisory_unlock_all()')->closeCursor();});
        try{self::assertTrue($this->refused(fn()=>$this->synchronize($sync,$a)));self::assertTrue($fired);self::assertTrue($inTransaction);self::assertSame($pid,$this->sessionId($this->official()));self::assertSame($before,$this->snapshot($a));self::assertFalse($this->official()->inTransaction());}finally{$this->setSyncClock($a,(int)$oldClock);}
    }
    public function testClosedPhysicalSessionAfterNativeAccountWriteCannotCommitPartialCache():void {
        $a=$this->account();$oldClock=$a->getMailAccount()->getLastMailboxSync();$this->setSyncClock($a,0);$before=$this->snapshot($a);$fired=false;$inTransaction=false;
        $sync=$this->sync(null,function()use(&$fired,&$inTransaction){$fired=true;$inTransaction=$this->official()->inTransaction();$this->official()->close();});
        try{try{$refused=$this->refused(fn()=>$this->synchronize($sync,$a));}catch(\OCP\DB\Exception){$refused=true;/* Native TTransactional may report rollback on the deliberately closed transaction. */}self::assertTrue($refused);self::assertTrue($fired);self::assertTrue($inTransaction);self::assertSame($before,$this->snapshot($a));self::assertFalse($this->official()->inTransaction());}finally{$this->setSyncClock($a,(int)$oldClock);}
    }
}
