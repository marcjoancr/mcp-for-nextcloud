<?php
declare(strict_types=1);
namespace OCP {interface IDBConnection {public function executeQuery(string $sql,array $params):object;}}
namespace OCA\Forms\Mcp\Service {
 class CircleRuntime {public static function readProviderParent(\OCA\Forms\Mcp\Service\CircleDetached\Lease $lease,\Closure $read):mixed{return$lease->metadataRead($read);}}
 class FreshCore {public function __construct(string $uid){}public function apps():self{return$this;}public function user():self{return$this;}public function isEnabledForUser(string $app,object $user):bool{return true;}}
 class CircleExistingIdentity {public static function read(object $db,string $uid):array{return['uid'=>$uid];}}
 class CircleReadConfig {public static function read(object $db,array $system):array{return[];}}
 class OriginReadAuthority {public static bool $active=false;public static function check():bool{return self::$active;}}
 class OriginContext {public static int $frames=0;public static function active():bool{return true;}public static function current():array{return['verified'=>['tool'=>'forms_transfer','uid'=>'actor']];}public static function transferSharingAuthority():array{return['frame'=>static fn()=>null];}public static function verifiedRead(\Closure $frame,\Closure $read):mixed{self::$frames++;OriginReadAuthority::$active=true;try{return$read();}finally{OriginReadAuthority::$active=false;}}}
}
namespace OCA\Forms\Mcp\Service\CircleDetached {
 class ShareWrapperRequest {public function readModel(Lease $lease,\Closure $read):mixed{return$lease->metadataRead($read);}}
 class SourceGate {public int $frames=0;public bool $active=false;public function assert(string $uid):void{}public function verifiedRead(string $uid,\Closure $read):mixed{if($this->active)throw new \RuntimeException('nested');$this->frames++;$this->active=true;try{return$read();}finally{$this->active=false;}}}
 final class ScopedFederatedUserService {public function getLocalFederatedUser(Lease $lease,\Closure $read):mixed{return$lease->metadataRead($read);}}
}
namespace {
require_once __DIR__.'/../src/Service/CircleDetached/Lease.php';
use OCA\Forms\Mcp\Service\{OriginContext,OriginReadAuthority,CircleDetached\Lease,CircleDetached\SourceGate,CircleDetached\ScopedFederatedUserService};
final class CircleMetadataSourceReadTest extends \PHPUnit\Framework\TestCase {
 private Lease $lease;private SourceGate $gate;private int $authority=0;
 protected function setUp():void{OriginContext::$frames=0;OriginReadAuthority::$active=false;$this->gate=new SourceGate();$this->lease=(new ReflectionClass(Lease::class))->newInstanceWithoutConstructor();$db=new class implements \OCP\IDBConnection {public function executeQuery(string $sql,array $params):object{return new class {public function fetchAll():array{return[];}public function closeCursor():void{}};}};foreach(['uid'=>'actor','connection'=>$db,'sources'=>$this->gate,'started'=>hrtime(true),'identity'=>['uid'=>'actor'],'configData'=>[],'keyhole'=>'0','readSystem'=>static fn()=>[],'assertAuthority'=>function():void{$this->authority++;}]as$k=>$v)(new ReflectionProperty($this->lease,$k))->setValue($this->lease,$v);}
 private function read(\Closure $read):mixed{return(new ScopedFederatedUserService())->getLocalFederatedUser($this->lease,$read);}
 public function testParentModelLookupUsesSourceAndWriteBarrier():void{\OCA\Forms\Mcp\Service\CircleRuntime::readProviderParent($this->lease,function():void{self::assertTrue($this->gate->active);$this->expectException(RuntimeException::class);$this->lease->assertNoMetadataWrite();});}
 public function testChildModelLookupUsesSourceAndWriteBarrier():void{(new \OCA\Forms\Mcp\Service\CircleDetached\ShareWrapperRequest())->readModel($this->lease,function():void{self::assertTrue($this->gate->active);$this->expectException(RuntimeException::class);$this->lease->assertNoMetadataWrite();});}
 public function testMetadataUsesBothClosedSourceAndAuthorityFrames():void{self::assertSame(42,$this->read(function():int{self::assertTrue($this->gate->active);self::assertTrue(OriginReadAuthority::check());return 42;}));self::assertSame(1,$this->gate->frames);self::assertSame(1,OriginContext::$frames);self::assertSame(2,$this->authority);self::assertFalse($this->gate->active);}
 public function testExistingAuthorityFrameStillChecksCircleSources():void{OriginReadAuthority::$active=true;$this->read(function():void{self::assertTrue($this->gate->active);});self::assertSame(1,$this->gate->frames);self::assertSame(0,OriginContext::$frames);}
 public function testReadFailureClosesBothFrames():void{$this->expectException(LogicException::class);try{$this->read(static function():never{throw new LogicException();});}finally{self::assertFalse($this->gate->active);self::assertFalse(OriginReadAuthority::check());$this->lease->assertNoMetadataWrite();}}
 public function testMetadataStillCannotReachWrite():void{$this->expectException(RuntimeException::class);try{$this->read(fn()=>$this->lease->assertNoMetadataWrite());}finally{self::assertFalse($this->gate->active);$this->lease->assertNoMetadataWrite();}}
 public function testReentrantMetadataUsesTheSameSourceFrame():void{self::assertSame(9,$this->read(fn()=>$this->read(function():int{self::assertTrue($this->gate->active);return 9;})));self::assertSame(1,$this->gate->frames);}
}
}
