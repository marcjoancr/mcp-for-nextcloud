<?php
declare(strict_types=1);
namespace OCP {interface IDBConnection {public function executeQuery(string $sql,array $params):object;}interface IURLGenerator {}}
namespace OC {class SystemConfig {}}
namespace Psr\Log {interface LoggerInterface {}}
namespace OCA\Forms\Mcp\Service {
class CircleReadData {public static function page(array $rows):array{return$rows;}}
class BulkDeadline {public static function limit():int{return 25;}}
class OriginReadAuthority {public static bool $active=true;public static function check():bool{return self::$active;}}
class OriginContext {public static string $tool='forms_transfer';public static function active():bool{return true;}public static function current():array{return['verified'=>['uid'=>'actor','tool'=>self::$tool]];}}
class FreshCore {public static bool $enabled=true;public static array $checks=[];public function __construct(string $uid){}public function apps():self{return$this;}public function user():self{return$this;}public function isEnabledForUser(string $app,object $user):bool{self::$checks[]=$app;return self::$enabled;}}
class FinalReadCore {public static function fresh(string $uid,object $db):FreshCore{return new FreshCore($uid);}}
class FinalReadConnection implements \OCP\IDBConnection {public function executeQuery(string $sql,array $params):object{return new class {public function fetchAll():array{return[];}public function closeCursor():void{}};}}
class CircleExistingIdentity {public static function read(object $db,string $uid):array{return['uid'=>$uid];}}
class CircleReadConfig {public static function read(object $db,array $system):array{return[];}}
}
namespace OCA\Forms\Mcp\Service\CircleDetached {class Reader {public function read(Lease $lease,\Closure $read):mixed{return$lease->readData($read);}}class SourceGate {public function assert(string $uid):void{}public function verifiedRead(string $uid,\Closure $read):mixed{return$read();}}class Config {public function __construct(mixed ...$args){}}class BoundModel {public function bindCircleLease(Lease $lease):void{}}class ModelManager extends BoundModel {}class CircleRequest extends BoundModel {}class MemberRequest extends BoundModel {}class MembershipRequest extends BoundModel {}class RemoteRequest extends BoundModel {}}
namespace {
require_once __DIR__.'/../src/Service/CircleDetached/Lease.php';
use OCA\Forms\Mcp\Service\{FreshCore,FinalReadConnection,OriginReadAuthority,OriginContext,CircleDetached\Lease,CircleDetached\SourceGate};
final class CircleFinalReadLeaseAdmissionTest extends \PHPUnit\Framework\TestCase {
 protected function setUp():void{FreshCore::$enabled=true;FreshCore::$checks=[];OriginReadAuthority::$active=true;OriginContext::$tool='forms_transfer';}
 private function lease(bool $final=true):Lease{$db=$final?new FinalReadConnection():new class implements \OCP\IDBConnection {public function executeQuery(string $sql,array $params):object{return new class {public function fetchAll():array{return[];}public function closeCursor():void{}};}};return new Lease('actor',$db,new \OC\SystemConfig(),new class implements \Psr\Log\LoggerInterface {},new class implements \OCP\IURLGenerator {},static fn()=>null,static fn()=>[],new SourceGate());}
 public function testOnlyFinalPureConstructorAvoidsDuplicatingTheFirstCheckpoint():void{$lease=$this->lease();self::assertSame([],FreshCore::$checks);$lease->checkpoint();self::assertSame(['forms','circles'],FreshCore::$checks);}
 public function testAppRevocationBeforeFirstDataReadIsStillDenied():void{$lease=$this->lease();FreshCore::$enabled=false;$ran=false;$this->expectException(RuntimeException::class);try{(new \OCA\Forms\Mcp\Service\CircleDetached\Reader())->read($lease,static function()use(&$ran):int{$ran=true;return 7;});}finally{self::assertFalse($ran);}}
 public function testAppRevocationDuringReadCannotPublishData():void{$lease=$this->lease();$published=false;try{(new \OCA\Forms\Mcp\Service\CircleDetached\Reader())->read($lease,static function():int{FreshCore::$enabled=false;return 7;});$published=true;}catch(RuntimeException){}self::assertFalse($published);}
 public function testEveryLaterCheckpointReadsCurrentApplicationState():void{$lease=$this->lease();$lease->checkpoint();FreshCore::$enabled=false;$this->expectException(RuntimeException::class);try{$lease->checkpoint();}finally{self::assertSame(['forms','circles','forms'],FreshCore::$checks);}}
 public function testOrdinaryConstructorRetainsItsImmediateCheck():void{$this->lease(false);self::assertSame(['forms','circles'],FreshCore::$checks);}
 public function testFinalConnectionOutsidePureTransferKeepsConstructorCheck():void{OriginReadAuthority::$active=false;$this->lease();self::assertSame(['forms','circles'],FreshCore::$checks);FreshCore::$checks=[];OriginReadAuthority::$active=true;OriginContext::$tool='forms_read';$this->lease();self::assertSame(['forms','circles'],FreshCore::$checks);}
}
}
