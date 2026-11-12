<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Mcp\Operations;
use OCA\Mcp\Mcp\Operations\{ActorRetired,DurableActorIdentity,OperationJournal};
use OCA\Mcp\Db\{ActorIncarnationsMapper,OperationsMapper};
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Tests\Unit\{SqliteDatabase,InMemoryConfig};
use OCA\Mcp\Migration\{Version002700Date20261107000000,Version002700Date20261111100000};
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\Config\IUserConfig;
use OCP\{IUserManager,IUser};
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class ActorIncarnationTest extends TestCase {
    private function fixture():array {
        $sql=new SqliteDatabase($this,[new Version002700Date20261107000000(),new Version002700Date20261111100000()]);$db=$sql->connection();
        $prefs=(object)['values'=>[],'failure'=>false];$config=$this->createMock(IUserConfig::class);
        $config->method('getValueString')->willReturnCallback(fn($uid,$app,$key,$default='')=>$prefs->values[$uid][$app][$key]??$default);
        $config->method('setValueString')->willReturnCallback(function($uid,$app,$key,$value)use($prefs):bool{if($prefs->failure)throw new \RuntimeException('Synthetic storage outage');$prefs->values[$uid][$app][$key]=$value;return true;});
        $users=$this->createMock(IUserManager::class);$users->method('get')->willReturn($this->createStub(IUser::class));
        $tx=$this->createMock(PolicyTransaction::class);$tx->method('underMutex')->willReturnCallback(function(\Closure $f)use($db){$db->beginTransaction();try{$r=$f();$db->commit();return $r;}catch(\Throwable $e){$db->rollBack();throw $e;}});
        $mapper=new ActorIncarnationsMapper($db);$make=fn()=>new DurableActorIdentity($mapper,$tx,$config,$users);$actors=$make();
        $clock=$this->createStub(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);$context=new ConnectionContext();
        $store=new OperationsMapper($db);$journal=new OperationJournal($store,new PlanState((new InMemoryConfig())->mock($this)),$context,$clock,$actors);
        return [$sql,$mapper,$prefs,$actors,$make,$journal,$store,$tx];
    }
    public function testFirstAdmissionConvergesAndExistingContextCannotAdoptRecreatedActor():void {
        [$sql,$mapper,$prefs,$old,$make,$journal,$store,$tx]=$this->fixture();$token=$old->capture('alice');self::assertSame($token,$make()->capture('alice'));
        $new=$tx->underMutex(fn()=>$make()->rotateBeforeCreation('alice'));self::assertNotSame($token,$new);self::assertSame($new,$make()->capture('alice'));
        $this->expectException(ActorRetired::class);$old->capture('alice');
    }
    public function testMissingWitnessAfterMissedDeleteAndCreateHooksCannotAdoptOldRegistry():void {
        [$sql,$mapper,$prefs,$actors,$make]=$this->fixture();$old=$actors->capture('alice');$prefs->values=[];
        try{$make()->capture('alice');self::fail('Missing native lifetime witness accepted');}catch(ActorRetired){}
        self::assertSame($old,$mapper->get('alice'),'A failed read must not replace the durable tombstone.');
    }
    public function testForeignTransactionAndWitnessPersistenceFailureCannotPublishRotation():void {
        [$sql,$mapper,$prefs,$actors,$make,$journal,$store,$tx]=$this->fixture();$before=$actors->capture('alice');$prefs->failure=true;
        try{$tx->underMutex(fn()=>$make()->rotateBeforeCreation('alice'));self::fail('Persistence failure ignored');}catch(\RuntimeException){}
        self::assertSame($before,$mapper->get('alice'));$prefs->failure=false;self::assertSame($before,$actors->capture('alice'));
        $this->expectException(\LogicException::class);$make()->rotateBeforeCreation('alice');
    }
    public function testOldInflightCheckpointCannotReplaceOriginalActorOrFinishAfterRecreation():void {
        [$sql,$mapper,$prefs,$actors,$make,$journal,$store,$tx]=$this->fixture();$plan=$journal->plan('mail_create_tag','alice',[],['plan_state'=>'p']);$id=$plan['operation_id'];$old=$actors->capture('alice');
        try{$journal->execute('mail_create_tag','alice',['operation_id'=>$id,'plan_state'=>'p'],function()use($journal,$tx,$make):array{
            $journal->recordAuthorization('alice',['v'=>1,'mail'=>[],'global'=>true,'files'=>[],'tools'=>[]]);
            $tx->underMutex(fn()=>$make()->rotateBeforeCreation('alice'));
            $journal->checkpoint(['tag_id'=>999]);return [];
        });self::fail('Retired operation returned a receipt');}catch(ActorRetired){}
        $row=$store->get($journal->scopeKey('alice'),$id);self::assertSame($old,$row['resource_refs']['actor_incarnation']);self::assertArrayNotHasKey('tag_id',$row['resource_refs']);self::assertSame('uncertain',$row['state']);
        self::assertSame(hash('sha256',"alice\0".'0'),$journal->scopeKey('alice'));
    }
    public function testLiveActorDeletedSourceHistorySurvivesCompactionWithoutProjectionOfProof():void {
        [$sql,$mapper,$prefs,$actors,$make,$journal,$store]=$this->fixture();$p=$journal->plan('mail_move_message','alice',[],['plan_state'=>'p']);
        $r=$journal->execute('mail_move_message','alice',['operation_id'=>$p['operation_id'],'plan_state'=>'p'],function()use($journal):array{$journal->recordAuthorization('alice',['v'=>1,'mail'=>[['account'=>3,'mailbox'=>7]],'global'=>false,'files'=>[],'tools'=>[]]);return [];});
        $store->compact(2000);self::assertSame('succeeded',$journal->get('alice',$p['operation_id'])['state']);
        self::assertSame([['account'=>3,'mailbox'=>7]],$journal->privateResources('alice',$p['operation_id'])['authorization']['mail']);
        self::assertStringNotContainsString($actors->capture('alice'),json_encode($r));
    }
    public function testPendingBackendDeletionIsNotDefinitiveRetirementAndFailedDeleteRestoresAdmission():void {
        [$sql,$mapper,$prefs,$actors]=$this->fixture();$token=$actors->capture('alice');$prefs->values['alice']['core']['deleted']='true';
        try{$actors->assertCurrent('alice',$token);self::fail('Pending deletion admitted');}catch(ActorRetired){self::fail('Pending deletion falsely retired actor');}catch(\RuntimeException){self::assertTrue(true);}
        unset($prefs->values['alice']['core']['deleted']);self::assertSame($token,$actors->capture('alice'));
    }

    public function testRetiredParentCannotFinalizeItsPreviouslyDelegatedReservation():void {
        [$sql,$mapper,$prefs,$actors,$make,$journal,$store,$tx]=$this->fixture();$p=$journal->plan('mail_set_message_flag','alice',['account_id'=>4],['plan_state'=>'p']);
        $descriptor=['account_id'=>4,'message_id'=>17,'mailbox_id'=>5,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','flag'=>'seen','value'=>true,'profile_revision'=>'p','cache_revision'=>'c'];
        try{$journal->execute('mail_set_message_flag','alice',['account_id'=>4,'operation_id'=>$p['operation_id'],'plan_state'=>'p'],function()use($journal,$descriptor,$tx,$make):array{
            $journal->delegateNative('alice',$descriptor);$tx->underMutex(fn()=>$make()->rotateBeforeCreation('alice'));return [];
        });self::fail('Retired parent finalized delegated evidence');}catch(ActorRetired){}
        $row=$store->get($journal->scopeKey('alice'),$p['operation_id']);self::assertSame('running',$row['state']);self::assertSame('prepared',$row['resource_refs']['mutation']['phase']);self::assertNotNull($row['target_key']);
    }

}
