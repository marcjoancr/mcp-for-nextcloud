<?php
declare(strict_types=1);
namespace OCP {interface IDBConnection {}class Server {public static function get(string $class):object{return new class implements IDBConnection {};}}}
namespace OCA\Circles\Model {class Member {public const TYPE_USER=1;}}
namespace OCA\Forms\Mcp\Service\CircleDetached {
 class Lease {public bool $closed=false;public string $uid='member';public int $type=1;public bool $local=true;public function readData(\Closure $read):mixed{return$read($this);}public function close():void{$this->closed=true;}public function circles():object{return new class($this){public function __construct(private Lease $lease){}public function getFederatedUserBySingleId(string $id):object{return new class($this->lease){public function __construct(private Lease $lease){}public function getUserId():string{return$this->lease->uid;}public function getUserType():int{return$this->lease->type;}public function getInstance():string{return'canonical-local';}};}};}public function config():object{return new class($this){public function __construct(private Lease $lease){}public function isLocalInstance(string $instance):bool{return$this->lease->local&&$instance==='canonical-local';}};}}
}
namespace OCA\Forms\Mcp\Service {
 class OriginReadAuthority {public static bool $active=false;public static function check():bool{return self::$active;}}
 class OriginContext {public static int $frames=0;public static function active():bool{return true;}public static function current():array{return['verified'=>['tool'=>'forms_transfer']];}public static function transferSharingAuthority():array{return['frame'=>static fn()=>null];}public static function verifiedRead(\Closure $frame,\Closure $read):mixed{self::$frames++;OriginReadAuthority::$active=true;try{return$read();}finally{OriginReadAuthority::$active=false;}}}
 class CircleExistingIdentity {public static string $single;public static function read(object $db,string $uid):array{return['circle_id'=>self::$single];}}
 class CircleReadRuntime {public static array $calls=[];public static mixed $value;public static function scoped(string $uid,bool $deferred,?object $connection=null):\Closure{return static function(string $method,array $arguments):mixed{self::$calls[]=[$method,$arguments,OriginReadAuthority::$active];return self::$value;};}public static function withProviderLease(string $uid,\Closure $work,?object $connection=null):mixed{throw new \RuntimeException('Provider lifetime used for pure recipient data');}}
}
namespace {
require_once __DIR__.'/../src/Service/CircleDetached/Reader.php';
require_once __DIR__.'/../src/Service/CircleRuntime.php';
use OCA\Forms\Mcp\Service\{CircleRuntime,CircleReadRuntime,OriginContext,OriginReadAuthority,CircleExistingIdentity};
use OCA\Forms\Mcp\Service\CircleDetached\{Reader,Lease};
final class CircleRecipientReadTest extends \PHPUnit\Framework\TestCase {
 private string $circle;private string $single;
 protected function setUp():void{$this->circle=str_repeat('c',31);$this->single=str_repeat('s',31);OriginContext::$frames=0;OriginReadAuthority::$active=false;CircleReadRuntime::$calls=[];CircleExistingIdentity::$single=$this->single;}
 public function testUidIsScalarAndItsLeaseAlwaysCloses():void{$lease=new Lease();self::assertSame('member',(new Reader(static fn()=>$lease))->recipientUid($this->single));self::assertTrue($lease->closed);}
 public function testRemoteRecipientCannotEscapeRead():void{$lease=new Lease();$lease->local=false;$this->expectException(RuntimeException::class);try{(new Reader(static fn()=>$lease))->recipientUid($this->single);}finally{self::assertTrue($lease->closed);}}
 public function testNonUserRecipientCannotEscapeRead():void{$lease=new Lease();$lease->type=2;$this->expectException(RuntimeException::class);try{(new Reader(static fn()=>$lease))->recipientUid($this->single);}finally{self::assertTrue($lease->closed);}}
 public function testUidReadUsesClosedVerifiedDataPath():void{CircleReadRuntime::$value='member';self::assertSame('member',CircleRuntime::recipientUid('actor',$this->circle,$this->single));self::assertSame([['recipientUid',['singleId'=>$this->single],true]],CircleReadRuntime::$calls);self::assertSame(1,OriginContext::$frames);}
 public function testMembershipReadUsesTheSameClosedPath():void{CircleReadRuntime::$value=true;CircleRuntime::assertRecipientMember('actor',$this->circle,'member',$this->single);self::assertSame([['isUserInCircle',['circleId'=>$this->circle,'userId'=>'member'],true]],CircleReadRuntime::$calls);self::assertSame(1,OriginContext::$frames);}
 public function testChangedRecipientIdentityIsDenied():void{CircleExistingIdentity::$single='different';$this->expectException(RuntimeException::class);try{CircleRuntime::assertRecipientMember('actor',$this->circle,'member',$this->single);}finally{self::assertSame([],CircleReadRuntime::$calls);}}
 public function testMembershipRevocationIsDenied():void{CircleReadRuntime::$value=false;$this->expectException(RuntimeException::class);CircleRuntime::assertRecipientMember('actor',$this->circle,'member',$this->single);}
 public function testExistingPureFrameIsReusedWithoutNesting():void{OriginReadAuthority::$active=true;CircleReadRuntime::$value='member';self::assertSame('member',CircleRuntime::recipientUid('actor',$this->circle,$this->single));self::assertSame(0,OriginContext::$frames);}
}
}
