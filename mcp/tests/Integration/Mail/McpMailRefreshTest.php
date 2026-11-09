<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailRefreshTest extends IntegrationTestCase {
    protected function setUp():void {parent::setUp();if(getenv('MCP_IT_WITH_MAIL')!=='1'||getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Native v5 fixture only');}
    private function fence():object {return new class implements \OCA\Mail\Service\RefreshFence {
        public int $reads=0;public int $dispatch=0;public array $cache=[];
        public function authorizeRead(array $b):bool {$this->reads++;return true;}
        public function beforeCache(array $b,array $p):bool {$this->dispatch++;return true;}
        public function recordCache(array $p):bool {$this->cache=$p;return true;}
    };}
    private function request(string $owner,int $account,int $box,bool $reset=false):array {$s=Server::get('OCA\\Mail\\Sync\\CacheSnapshot')->capture($owner,$account,$box);return ['kind'=>'refresh','account_id'=>$account,'mailbox_id'=>$box,'mailbox'=>$s['mailbox'],'profile_revision'=>str_repeat('a',64),'cache_revision'=>$s['fingerprint'],'allow_cache_reset'=>$reset];}
    public function testBoundedNativeSnapshotUpdatesOnlyItsCacheAndPreservesExactIncarnations():void {
        $owner=MailActor::ALICE;$accountId=MailActor::run($owner,'accounts')['accounts'][0]['id'];$f=MailNativeMoveActor::fixture($owner,$accountId);$a=Server::get('OCA\\Mail\\Service\\AccountService')->find($owner,$accountId);$manager=Server::get('OCA\\Mail\\Service\\MailManager');$box=$manager->getMailbox($owner,$f['boxes']['source']);$mapper=Server::get('OCA\\Mail\\Db\\MessageMapper');$first=$manager->getMessage($owner,$f['messages'][0]);$first->setPreviewText('C6 preserved preview');$mapper->update($first);$client=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($a,false);$snap=Server::get('OCA\\Mail\\Sync\\CacheSnapshot');$safe=MailNativeMutationActor::safety($owner);$other=$snap->capture($owner,$accountId,$f['boxes']['destination'])['fingerprint'];$events=0;Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->addListener(\OCA\Mail\Events\SynchronizationEvent::class,static function()use(&$events):void{$events++;});
        try {
            $client->store($box->getName(),['ids'=>new \Horde_Imap_Client_Ids([$first->getUid()]),'add'=>['\\Flagged','$reviewed']]);
            $newUid=Server::get('OCA\\Mail\\IMAP\\MessageMapper')->save($client,$box,"From: fixture@example.test\r\nTo: fixture@example.test\r\nSubject: C6 new\r\nMessage-ID: <same-c3@example.test>\r\n\r\nNew",[]);
            $fence=$this->fence();$r=$this->request($owner,$accountId,$box->getId());$result=Server::get('OCA\\Mail\\Service\\GuardedMailboxRefresh')->refresh($owner,$r,$fence);
            self::assertGreaterThan(3,$fence->reads);self::assertSame(1,$fence->dispatch);self::assertSame(6,$result['cache']['messages']);self::assertFalse($result['cache']['reset']);self::assertSame('native_rebuild_pending',$result['cache']['threads_state']);$fresh=$manager->getMessage($owner,$first->getId());self::assertSame($first->getUid(),$fresh->getUid());self::assertSame('C6 preserved preview',$fresh->getPreviewText());$tags=Server::get('OCA\\Mail\\Db\\TagMapper');$tag=$tags->getTagByImapLabel('$reviewed',$owner);self::assertTrue($tags->exactAssociations()->has($owner,$first->getId(),$tag->getId()));self::assertFalse($tags->exactAssociations()->has($owner,$f['messages'][1],$tag->getId()));self::assertTrue($fresh->getFlagFlagged());self::assertSame($first->getFlagSeen(),$fresh->getFlagSeen());self::assertCount(1,$mapper->findByUids($box,[$newUid]));self::assertSame('snapshot',$snap->freshness($owner,$accountId,$box->getId())['status']);self::assertSame($other,$snap->capture($owner,$accountId,$f['boxes']['destination'])['fingerprint']);self::assertSame(0,$events);self::assertSame($safe,MailNativeMutationActor::safety($owner));
            $db=Server::get(\OCP\IDBConnection::class);$q=$db->getQueryBuilder();$q->update('mail_messages')->set('flag_flagged',$q->createNamedParameter(false,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))->where($q->expr()->eq('id',$q->createNamedParameter($first->getId())))->executeStatement();self::assertSame('stale',$snap->freshness($owner,$accountId,$box->getId())['status']);$nativeClient=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient($a);try{$nativeBox=$manager->getMailbox($owner,$box->getId());Server::get('OCA\\Mail\\Service\\Sync\\ImapToDbSynchronizer')->sync($a,$nativeClient,$nativeBox,Server::get(\Psr\Log\LoggerInterface::class));}finally{$nativeClient->logout();}self::assertSame($first->getId(),$manager->getMessage($owner,$first->getId())->getId());self::assertCount(6,$snap->capture($owner,$accountId,$box->getId())['messages']);self::assertSame($safe,MailNativeMutationActor::safety($owner));
        }finally{$client->logout();MailNativeMoveActor::cleanup($owner,$f);}
    }
    public function testLegacyResetRequiresReviewAndAllocatesNewIds():void {
        $owner=MailActor::ALICE;$account=MailActor::run($owner,'accounts')['accounts'][0]['id'];$f=MailNativeMoveActor::fixture($owner,$account);$db=Server::get(\OCP\IDBConnection::class);$box=$f['boxes']['source'];$snap=Server::get('OCA\\Mail\\Sync\\CacheSnapshot');
        try {
            $q=$db->getQueryBuilder();$q->update('mail_messages')->set('tag_cache_profile',$q->createNamedParameter(null,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL))->where($q->expr()->eq('mailbox_id',$q->createNamedParameter($box)))->executeStatement();$s=$snap->capture($owner,$account,$box);self::assertTrue($s['needs_reset']);$fence=$this->fence();$refused=false;try{Server::get('OCA\\Mail\\Service\\GuardedMailboxRefresh')->refresh($owner,$this->request($owner,$account,$box),$fence);}catch(\RuntimeException){$refused=true;}self::assertTrue($refused);self::assertSame(0,$fence->reads);self::assertSame($s['fingerprint'],$snap->capture($owner,$account,$box)['fingerprint']);
            $out=Server::get('OCA\\Mail\\Service\\GuardedMailboxRefresh')->refresh($owner,$this->request($owner,$account,$box,true),$this->fence());self::assertTrue($out['cache']['reset']);$now=$snap->capture($owner,$account,$box);self::assertSame([],array_intersect($f['messages'],array_column($now['messages'],'id')));self::assertFalse($now['needs_reset']);
        }finally{MailNativeMoveActor::cleanup($owner,$f);}
    }
    public function testCacheRaceConnectionLossAndReceiptLossNeverReplay():void {
        $owner=MailActor::ALICE;$account=MailActor::run($owner,'accounts')['accounts'][0]['id'];$f=MailNativeMoveActor::fixture($owner,$account);$journal=Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class);$store=Server::get(\OCA\Mcp\Mcp\Operations\OperationStore::class);$db=Server::get(\OCP\IDBConnection::class);$box=$f['boxes']['source'];$snap=Server::get('OCA\\Mail\\Sync\\CacheSnapshot');$safe=MailNativeMutationActor::safety($owner);
        try {foreach(['read_revoked','cache_race','connection_loss','cache_receipt']as$mode){
            $request=$this->request($owner,$account,$box);$before=$snap->capture($owner,$account,$box)['fingerprint'];$args=['account_id'=>$account,'mailbox_id'=>$box];$p=$journal->plan('mail_refresh_mailbox',$owner,$args,['plan_state'=>'fixture']);$args+=['operation_id'=>$p['operation_id'],'plan_state'=>'fixture'];$calls=0;
            try {
                $effect=function()use($journal,$store,$owner,$request,$mode,$db,$f,&$calls):array {$calls++;$lease=$journal->delegateNative($owner,$request);$inner=new \OCA\Mcp\Mcp\Operations\NativeOperationFence($store,$lease,$request,static fn():bool=>true,\OCA\Mcp\Modules\Mail\Mutations\RefreshRequest::remote(...),\OCA\Mcp\Modules\Mail\Mutations\RefreshRequest::cache(...));$inner=new \OCA\Mcp\Modules\Mail\Mutations\RefreshOperationFence($store,$lease,$request,static fn():bool=>true,$inner);
                    $fence=new class($inner,$mode,$db,$f) implements \OCA\Mail\Service\RefreshFence {
                        public function __construct(private object $inner,private string $mode,private object $db,private array $fixture){}
                        public function authorizeRead(array $b):bool {return $this->mode!=='read_revoked'&&$this->inner->authorizeRead($b);}
                        public function beforeCache(array $b,array $p):bool {if(!$this->inner->beforeCache($b,$p))return false;if($this->mode==='connection_loss')$this->db->close();if($this->mode==='cache_race'){$q=$this->db->getQueryBuilder();$q->update('mail_messages')->set('flag_flagged',$q->createNamedParameter(true,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))->where($q->expr()->eq('id',$q->createNamedParameter($this->fixture['messages'][0])))->executeStatement();}return true;}
                        public function recordCache(array $p):bool {return $this->mode!=='cache_receipt'&&$this->inner->recordCache($p);}
                    };return Server::get('OCA\\Mail\\Service\\GuardedMailboxRefresh')->refresh($owner,$request,$fence);
                };
                $out=$journal->execute('mail_refresh_mailbox',$owner,$args,$effect);self::assertSame($mode==='read_revoked'?'failed_before_effect':'uncertain',$out['operation']['state'],$mode);self::assertSame($mode==='read_revoked'?'prepared':'cache_pending',$out['operation']['mutation']['phase'],$mode);$replay=$journal->execute('mail_refresh_mailbox',$owner,$args,$effect);self::assertTrue($replay['operation']['replayed']);self::assertSame(1,$calls);self::assertFalse($out['operation']['safe_to_retry']);
                if(in_array($mode,['read_revoked','connection_loss'],true))self::assertSame($before,$snap->capture($owner,$account,$box)['fingerprint']);if($mode==='cache_receipt')self::assertSame('snapshot',$snap->freshness($owner,$account,$box)['status']);
            }finally{$q=$db->getQueryBuilder();$q->delete('mcp_operations')->where($q->expr()->eq('operation_id',$q->createNamedParameter($args['operation_id'])))->andWhere($q->expr()->eq('scope_key',$q->createNamedParameter($journal->scopeKey($owner))))->executeStatement();}
        }self::assertSame($safe,MailNativeMutationActor::safety($owner));}finally{MailNativeMoveActor::cleanup($owner,$f);}
    }
    public function testOrdinaryNativeReadCacheCannotDeletePersistentRows():void {
        $owner=MailActor::ALICE;$id=MailActor::run($owner,'accounts')['accounts'][0]['id'];$f=MailNativeMoveActor::fixture($owner,$id);
        try{$a=Server::get('OCA\\Mail\\Service\\AccountService')->find($owner,$id);$box=Server::get('OCA\\Mail\\Db\\MailboxMapper')->findById($f['boxes']['source']);$m=Server::get('OCA\\Mail\\Service\\MailManager')->getMessage($owner,$f['messages'][0]);$cache=Server::get('OCA\\Mail\\Cache\\HordeCacheFactory')->newCache($a);$cache->deleteMsgs($box->getName(),[$m->getUid()]);self::assertSame($m->getId(),Server::get('OCA\\Mail\\Service\\MailManager')->getMessage($owner,$m->getId())->getId());}finally{MailNativeMoveActor::cleanup($owner,$f);}
    }
    public function testActualWireReaderRejectsUnboundedOrUnstableObservations():void {
        $owner=MailActor::ALICE;$id=MailActor::run($owner,'accounts')['accounts'][0]['id'];foreach(['empty','count','range','line','literal','epoch','unstable','no_modseq','copyuid','quoted_copyuid','space_esearch']as$mode){$r=MailActor::run($owner,'c6-protocol',['account_id'=>$id,'mode'=>$mode]);self::assertSame($mode==='empty',$r['accepted'],$mode);self::assertSame(0,$r['writes'],$mode);if($mode==='empty')self::assertSame(2,count(array_filter($r['commands'],static fn($c)=>$c==='UID SEARCH')),'Even an empty count must have two actual UID observations.');}
    }
    public function testNativeSessionMutexSurvivesOldDtoUnlockAndReleasesWhenChildIsKilled():void {
        $owner=MailActor::ALICE;$account=MailActor::run($owner,'accounts')['accounts'][0]['id'];$f=MailNativeMoveActor::fixture($owner,$account);$mapper=Server::get('OCA\\Mail\\Db\\MailboxMapper');$box=$mapper->findById($f['boxes']['source']);$child=null;$pipes=[];
        try {
            $mapper->lockForNewSync($box);self::assertFalse(MailActor::run($owner,'c6-lease',['mailbox_id'=>$box->getId()])['acquired']);$mapper->unlockFromNewSync($box);$new=clone $box;$mapper->lockForNewSync($new);$mapper->unlockFromNewSync($box);self::assertFalse(MailActor::run($owner,'c6-lease',['mailbox_id'=>$box->getId()])['acquired']);$mapper->unlockFromNewSync($new);
            $child=proc_open([PHP_BINARY,__DIR__.'/mail-actor.php',$owner,'c6-lease-hold'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode(['mailbox_id'=>$box->getId()]));fclose($pipes[0]);self::assertSame("owned\n",fgets($pipes[1]));self::assertFalse(MailActor::run($owner,'c6-lease',['mailbox_id'=>$box->getId()])['acquired']);proc_terminate($child,9);fclose($pipes[1]);fclose($pipes[2]);proc_close($child);$child=null;self::assertTrue(MailActor::run($owner,'c6-lease',['mailbox_id'=>$box->getId()])['acquired']);
            $db=Server::get(\OCP\IDBConnection::class);$old=\OCA\Mail\Sync\MailboxLease::acquire($db,Server::get(\OCP\IConfig::class)->getSystemValueString('dbtableprefix','oc_'),$box->getId());$db->close();$next=\OCA\Mail\Sync\MailboxLease::acquire($db,Server::get(\OCP\IConfig::class)->getSystemValueString('dbtableprefix','oc_'),$box->getId());$old->release();$next->assertOwned();self::addToAssertionCount(1);$next->release();
        }finally{if(is_resource($child)){proc_terminate($child,9);proc_close($child);}MailNativeMoveActor::cleanup($owner,$f);}
    }
}
