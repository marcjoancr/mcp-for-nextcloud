<?php
declare(strict_types=1);
namespace OCP {interface IDBConnection {public function inTransaction():bool;}}
namespace OCA\Forms\Mcp\Service {
 class BulkStore {public array $evidence;public array $lookups=[];public function one(string $job,string $kind,int $child):array{$this->lookups[]=[$job,$kind,$child];return$this->evidence;}}
 class OriginContext {public static function assertJobInput(array $job):void{}}
 class CircleExistingIdentity {public static array $identity;public static int $reads=0;public static function read(object $db,string $uid):array{self::$reads++;return self::$identity;}}
}
namespace {
require_once __DIR__.'/../src/Service/BulkTransferShares.php';
use OCA\Forms\Mcp\Service\{BulkStore,BulkTransferShares,CircleExistingIdentity};
final class CircleStagedRecipientIdentityTest extends \PHPUnit\Framework\TestCase {
 private BulkStore $store;private BulkTransferShares $service;private object $db;private array $job=['id'=>'job','action'=>'forms_transfer','phase'=>'apply'];private array $record=['approved_tuple'=>['type'=>7,'recipient'=>'circle']];private array $child=['parent'=>3,'share_with'=>'single'];
 protected function setUp():void{$this->db=new class implements \OCP\IDBConnection {public bool $live=true;public function inTransaction():bool{return$this->live;}};$this->store=new BulkStore();CircleExistingIdentity::$reads=0;CircleExistingIdentity::$identity=['uid'=>'member','circle_id'=>'single','member_id'=>'single','creation'=>'2026-01-01 00:00:00','source'=>1];$this->store->evidence=['job_id'=>'job','source_child_id'=>4,'parent'=>3,'circle_id'=>'circle','single_id'=>'single','identity'=>CircleExistingIdentity::$identity];$this->service=new BulkTransferShares($this->db,$this->store);}
 private function resolve():string{return(new ReflectionMethod($this->service,'stagedCircleRecipientUid'))->invoke($this->service,$this->job,$this->record,$this->child,4);}
 public function testOnlyTheIdentifierIsReusedAndItsIdentityIsFresh():void{self::assertSame('member',$this->resolve());self::assertSame('member',$this->resolve());self::assertSame(2,CircleExistingIdentity::$reads);self::assertSame([['job','circle_ids',4],['job','circle_ids',4]],$this->store->lookups);}
 public function testEverySourceBindingMustMatch():void{foreach(['job_id'=>'other','source_child_id'=>5,'parent'=>9,'circle_id'=>'other','single_id'=>'other']as$key=>$value){$before=$this->store->evidence;$this->store->evidence[$key]=$value;$denied=false;try{$this->resolve();}catch(RuntimeException){$denied=true;}self::assertTrue($denied);$this->store->evidence=$before;}}
 public function testChangedCanonicalIdentityAndIncarnationAreDenied():void{foreach(['circle_id'=>'other','member_id'=>'other','creation'=>'2026-02-01 00:00:00']as$key=>$value){$before=CircleExistingIdentity::$identity;CircleExistingIdentity::$identity[$key]=$value;$denied=false;try{$this->resolve();}catch(RuntimeException){$denied=true;}self::assertTrue($denied);CircleExistingIdentity::$identity=$before;}}
 public function testMembershipAndPermissionFieldsAreNotAcceptedAsEvidence():void{$this->store->evidence['member_allowed']=true;$this->expectException(RuntimeException::class);$this->resolve();}
 public function testMissingIdentityCannotBecomeUidOnlyAuthority():void{unset($this->store->evidence['identity']);$this->expectException(RuntimeException::class);$this->resolve();}
 public function testEndedTransactionCannotResolveIdentifier():void{$this->db->live=false;$this->expectException(RuntimeException::class);$this->resolve();}
}
}
