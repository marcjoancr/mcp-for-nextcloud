<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory,McpClient,ToolPermissionFixture,ToolReply};
use OCP\{Server,IUserManager,IConfig,IDBConnection};
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailActorIncarnationTest extends IntegrationTestCase {
    private function data(ToolReply $reply):array {self::assertTrue($reply->ok(),'Synthetic actor operation must be admitted before its deletion.');return $reply->structured()!==[]?$reply->structured():$reply->json();}
    private function allow(string $uid):void {
        $policy=Server::get(GrantPolicy::class);$policy->setGlobalEnabled(true);$policy->setEligible($uid,true);$policy->setConnected($uid,true);
        ToolPermissionFixture::assign($uid,[ToolGrantsMapper::SERVICE=>'allow','mail_create_tag'=>'allow','mail_get_operation'=>'allow','mail_list_operations'=>'allow']);
    }
    public function testRecreatedBasicUidCannotReadOrReplayPreviousActorsMailOperation():void {
        $uid='mcp-it-scope-owner-'.bin2hex(random_bytes(8));$users=Server::get(IUserManager::class);$config=Server::get(IConfig::class);$global=$config->getAppValue('mcp',GrantPolicy::SERVICE_KEY,'__missing__');
        try{
            self::assertNotFalse($users->createUser($uid,bin2hex(random_bytes(24))));$this->allow($uid);$client=new McpClient(LoginFactory::basic($uid));
            $args=['name'=>'Synthetic old owner label','color'=>'#123456'];$plan=$this->data($client->tool('mail_create_tag',$args));$confirmed=$args+['confirm'=>true,'operation_id'=>$plan['operation_id'],'plan_state'=>$plan['plan_state']];
            $done=$this->data($client->tool('mail_create_tag',$confirmed));self::assertSame('succeeded',$done['operation']['state']);self::assertTrue($client->tool('mail_get_operation',['operation_id'=>$plan['operation_id']])->ok());
            self::assertTrue($users->get($uid)->delete());self::assertNotFalse($users->createUser($uid,bin2hex(random_bytes(24))));$this->allow($uid);$replacement=new McpClient(LoginFactory::basic($uid));
            $effectsBefore=$this->countRows('mail_tags','user_id',$uid);$occupiedBefore=$this->countRows('mcp_operations','actor_uid',$uid);
            $status=$replacement->tool('mail_get_operation',['operation_id'=>$plan['operation_id']]);$replay=$replacement->tool('mail_create_tag',$confirmed);
            self::assertFalse($status->ok(),'A recreated Basic UID inherited the former actor receipt.');self::assertFalse($replay->ok(),'A recreated Basic UID inherited the former actor replay.');
            self::assertSame($effectsBefore,$this->countRows('mail_tags','user_id',$uid),'Old operation ID caused another native tag effect.');self::assertSame($occupiedBefore,$this->countRows('mcp_operations','actor_uid',$uid),'Old operation ID acquired another reservation.');
        }finally{
            $users->get($uid)?->delete();$db=Server::get(IDBConnection::class);
            foreach(['mcp_operations'=>'actor_uid','mail_tags'=>'user_id'] as $table=>$column){$q=$db->getQueryBuilder();$q->delete($table)->where($q->expr()->eq($column,$q->createNamedParameter($uid)))->executeStatement();}
            if($global==='__missing__')$config->deleteAppValue('mcp',GrantPolicy::SERVICE_KEY);else $config->setAppValue('mcp',GrantPolicy::SERVICE_KEY,$global);
        }
    }
    private function countRows(string $table,string $column,string $uid):int {
        $q=$this->official()->getQueryBuilder();$r=$q->select($column)->from($table)->where($q->expr()->eq($column,$q->createNamedParameter($uid)))->executeQuery();
        try{return count($r->fetchAll());}finally{$r->closeCursor();}
    }
    private function actors():\OCA\Mcp\Mcp\Operations\DurableActorIdentity {
        return new \OCA\Mcp\Mcp\Operations\DurableActorIdentity(Server::get(\OCA\Mcp\Db\ActorIncarnationsMapper::class),Server::get(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class),Server::get(\OCP\Config\IUserConfig::class),Server::get(IUserManager::class));
    }
    public function testRegistryIsDurableBeforeBackendPublicationAndFailedDeletePreservesIt():void {
        $uid='mcp-it-scope-owner-'.bin2hex(random_bytes(8));$users=Server::get(IUserManager::class);$events=Server::get(\OCP\EventDispatcher\IEventDispatcher::class);$other=new \OCA\Mcp\Db\ActorIncarnationsMapper($this->openIndependentConnection());$seen=false;
        $listener=function($event)use($uid,$users,$other,&$seen):void{if($event->getUid()!==$uid)return;self::assertFalse($users->userExists($uid));self::assertNotNull($other->get($uid));$seen=true;};
        $events->addListener(\OCP\User\Events\BeforeUserCreatedEvent::class,$listener,-1000);
        try {
            $user=$users->createUser($uid,bin2hex(random_bytes(24)));self::assertNotFalse($user);self::assertTrue($seen);$actors=$this->actors();$before=$actors->capture($uid);$user->getHome();
            $history=Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class);$plan=$history->plan('mail_create_tag',$uid,[],['plan_state'=>'synthetic-history']);$history->execute('mail_create_tag',$uid,['operation_id'=>$plan['operation_id'],'plan_state'=>'synthetic-history'],static fn():array=>[]);
            $property=new \ReflectionProperty(\OC\User\User::class,'backend');$backend=$property->getValue($user);
            $during=function()use($actors,$uid,$before):void{try{$actors->assertCurrent($uid,$before);self::fail('Pending deletion was admitted');}catch(\OCA\Mcp\Mcp\Operations\ActorRetired){self::fail('An unconfirmed deletion was treated as definite actor retirement');}catch(\RuntimeException){self::assertTrue(true);}};
            $failed=new class($during) extends \OC\User\Database {public function __construct(private \Closure $during){}public function deleteUser($uid){($this->during)();return false;}};
            try{$property->setValue($user,$failed);self::assertFalse($user->delete());}finally{$property->setValue($user,$backend);}
            self::assertSame($before,$actors->capture($uid));self::assertSame($before,$other->get($uid));self::assertSame('succeeded',$history->get($uid,$plan['operation_id'])['state']);
        }finally{$events->removeListener(\OCP\User\Events\BeforeUserCreatedEvent::class,$listener);$users->get($uid)?->delete();}
    }
    public function testCreationPersistenceFailureAbortsBeforeBackendPublication():void {
        $uid='mcp-it-scope-owner-'.bin2hex(random_bytes(8));$users=Server::get(IUserManager::class);$events=Server::get(\OCP\EventDispatcher\IEventDispatcher::class);
        $registry=$this->createMock(\OCA\Mcp\Db\ActorIncarnationsMapper::class);$registry->method('rotate')->willThrowException(new \RuntimeException('Synthetic registry write failure'));
        $actors=new \OCA\Mcp\Mcp\Operations\DurableActorIdentity($registry,Server::get(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class),Server::get(\OCP\Config\IUserConfig::class),$users);
        $creation=new \OCA\Mcp\Listener\ConnectionToolsCreationListener(Server::get(\OCA\Mcp\OAuth\ConnectionToolsStore::class),Server::get(ToolGrantsMapper::class),Server::get(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class),$actors);
        $listener=function($event)use($uid,$creation):void{if($event->getUid()===$uid)$creation->handle($event);};$events->addListener(\OCP\User\Events\BeforeUserCreatedEvent::class,$listener,1000);
        try{try{$users->createUser($uid,bin2hex(random_bytes(24)));self::fail('Backend published an actor without durable identity.');}catch(\RuntimeException $e){self::assertSame('Synthetic registry write failure',$e->getMessage());}self::assertFalse($users->userExists($uid));}
        finally{$events->removeListener(\OCP\User\Events\BeforeUserCreatedEvent::class,$listener);$users->get($uid)?->delete();}
    }
    public function testMissedCreationHookAndLateDeletionEventCannotRestoreOldOwnership():void {
        $uid='mcp-it-scope-owner-'.bin2hex(random_bytes(8));$users=Server::get(IUserManager::class);
        try {
            $old=$users->createUser($uid,bin2hex(random_bytes(24)));$before=$this->actors()->capture($uid);$backend=(new \ReflectionProperty(\OC\User\User::class,'backend'))->getValue($old);
            self::assertTrue($old->delete());self::assertTrue($backend->createUser($uid,bin2hex(random_bytes(24))));
            // Native backend publication without the app's Before listener models disabled/missed hooks.
            try{$this->actors()->capture($uid);self::fail('A missed hook adopted the old actor.');}catch(\OCA\Mcp\Mcp\Operations\ActorRetired){}
            self::assertSame($before,Server::get(\OCA\Mcp\Db\ActorIncarnationsMapper::class)->get($uid));
            self::assertTrue($users->get($uid)->delete());self::assertNotFalse($users->createUser($uid,bin2hex(random_bytes(24))));$new=$this->actors();$after=$new->capture($uid);self::assertNotSame($before,$after);
            Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->dispatchTyped(new \OCP\User\Events\UserDeletedEvent($old));
            self::assertSame($after,$new->capture($uid));
        }finally{$users->get($uid)?->delete();}
    }
    public function testOldInflightTransactionRollsBackAfterIndependentRealRecreation():void {
        $uid='mcp-it-scope-owner-'.bin2hex(random_bytes(8));$users=Server::get(IUserManager::class);$db=$this->official();$key=bin2hex(random_bytes(32));
        try {
            self::assertNotFalse($users->createUser($uid,bin2hex(random_bytes(24))));$journal=Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class);$p=$journal->plan('mail_create_tag',$uid,[],['plan_state'=>'synthetic']);$id=$p['operation_id'];$before=$this->actors()->capture($uid);
            try{$journal->execute('mail_create_tag',$uid,['operation_id'=>$id,'plan_state'=>'synthetic'],function()use($journal,$uid,$db,$key):array{
                $journal->recordAuthorization($uid,['v'=>1,'mail'=>[],'global'=>true,'files'=>[],'tools'=>[]]);
                $journal->atomicLocal($db,function()use($uid,$db,$key):void {
                    $q=$db->getQueryBuilder();$q->insert('mcp_operation_origins')->setValue('native_key',$q->createNamedParameter($key))->setValue('scope_key',$q->createNamedParameter('synthetic'))->setValue('operation_id',$q->createNamedParameter('rolled-back'))->executeStatement();
                    self::assertSame(['deleted'=>true,'created'=>true],MailActor::run(MailActor::ALICE,'actor-incarnation',['uid'=>$uid,'action'=>'recreate']));
                });return [];
            });self::fail('Retired inflight actor completed');}catch(\OCA\Mcp\Mcp\Operations\ActorRetired|\OCA\Mcp\Mcp\Operations\BeforeEffectFailure){}
            self::assertNull((new \OCA\Mcp\Db\OperationOriginsMapper($db))->get($key));
            $row=Server::get(\OCA\Mcp\Db\OperationsMapper::class)->get($journal->scopeKey($uid),$id);self::assertSame($before,$row['resource_refs']['actor_incarnation']);self::assertArrayNotHasKey('local_completed',$row['resource_refs']);
            try{Server::get(\OCA\Mcp\Modules\Mail\Access\MailFreshAccess::class)->assertResource($uid,'mail_create_tag',1);self::fail('Scalar callback adopted replacement actor');}catch(\OCA\Mcp\Mcp\Operations\ActorRetired){}
        }finally{$users->get($uid)?->delete();}
    }

    public function testConcurrentFirstAdmissionUsesOneDurableIdentityAcrossIndependentProcesses():void {
        $uid='mcp-it-scope-owner-'.bin2hex(random_bytes(8));$users=Server::get(IUserManager::class);$processes=[];
        try {
            self::assertNotFalse($users->createUser($uid,bin2hex(random_bytes(24))));
            // This random account has never admitted an operation: model installing MCP for an existing user.
            $q=$this->official()->getQueryBuilder();$q->delete('mcp_actor_incarnations')->where($q->expr()->eq('actor_uid',$q->createNamedParameter($uid)))->executeStatement();
            Server::get(\OCP\Config\IUserConfig::class)->deleteUserConfig($uid,'mcp',\OCA\Mcp\Mcp\Operations\DurableActorIdentity::WITNESS);
            for($i=0;$i<2;$i++) {
                $process=proc_open([PHP_BINARY,'-d','memory_limit=512M',__DIR__.'/mail-actor.php',MailActor::ALICE,'actor-incarnation'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                self::assertIsResource($process);fwrite($pipes[0],json_encode(['uid'=>$uid,'action'=>'capture'],JSON_THROW_ON_ERROR));fclose($pipes[0]);$processes[]=[$process,$pipes];
            }
            $proofs=[];foreach($processes as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),'Independent actor failed');$proofs[]=json_decode($out,true,16,JSON_THROW_ON_ERROR);}$processes=[];
            self::assertSame($proofs[0],$proofs[1]);self::assertSame(['fingerprint'=>hash('sha256',$this->actors()->capture($uid))],$proofs[0]);
            self::assertSame(1,$this->countRows('mcp_actor_incarnations','actor_uid',$uid));
        }finally{foreach($processes as [$process,$pipes]){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}$users->get($uid)?->delete();}
    }

}
