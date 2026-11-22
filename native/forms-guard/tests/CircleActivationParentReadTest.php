<?php
declare(strict_types=1);
namespace OCP {class Server {public static bool $transaction=true;public static function get(string $class):object{return new class {public function inTransaction():bool{return Server::$transaction;}};}}}
namespace OCA\Forms\Mcp\Service {
 class BulkStore {}
 class OriginReadAuthority {public static bool $active=false;public static function check():bool{return self::$active;}}
 class OriginContext {public static int $reads=0;public static bool $match=true;public static function assertJobInput(array $job):void{if(!self::$match)throw new \RuntimeException('different input');}public static function transferSharingAuthority():array{return['frame'=>static fn()=>null];}public static function verifiedRead(\Closure $frame,\Closure $read):mixed{self::$reads++;OriginReadAuthority::$active=true;try{return$read();}finally{OriginReadAuthority::$active=false;}}}
 final class CircleNativeShareWrapperService {public function getChild(CircleDetached\ShareWriteBoundary $cap,\Closure $read):mixed{return$cap->readChild($read);}}
 final class CircleBoundaryProvider {public function getShareById(CircleDetached\ShareWriteBoundary $cap,\Closure $read):mixed{return$cap->readParent($read);}}
}
namespace {
require_once __DIR__.'/../src/Service/CircleDetached/ShareWriteBoundary.php';
use OCA\Forms\Mcp\Service\{BulkStore,CircleBoundaryProvider,OriginContext,OriginReadAuthority,CircleDetached\ShareWriteBoundary};
final class CircleActivationParentReadTest extends \PHPUnit\Framework\TestCase {
 protected function setUp():void{\OCP\Server::$transaction=true;OriginContext::$reads=0;OriginContext::$match=true;OriginReadAuthority::$active=false;}
 private function cap(string $mode='activate-parent',string $phase='apply'):ShareWriteBoundary{$r=new ReflectionClass(ShareWriteBoundary::class);$cap=$r->newInstanceWithoutConstructor();$r->getConstructor()->invokeArgs($cap,[new BulkStore(),['id'=>'job','phase'=>$phase],'managed_shares',3,str_repeat('a',64),3,31,'actor','target',11,'circle',null,$mode,0]);return$cap;}
 private function read(ShareWriteBoundary $cap,\Closure $read):mixed{return(new CircleBoundaryProvider())->getShareById($cap,$read);}
 public function testMetadataReadFlagExistsOnlyWithinBoundActivationRead():void{$cap=$this->cap();self::assertFalse($cap->activationReadActive());$this->read($cap,static function()use($cap):void{self::assertTrue($cap->activationReadActive());});self::assertFalse($cap->activationReadActive());}
 public function testStagingCannotBorrowActivationMetadataFlag():void{OriginReadAuthority::$active=true;self::assertFalse($this->cap('insert-parent','stage_shares')->activationReadActive());}
 public function testOnlyActivationLookupUsesClosedRead():void{self::assertSame('value',$this->read($this->cap(),static function():string{self::assertTrue(OriginReadAuthority::check());return'value';}));self::assertSame(1,OriginContext::$reads);self::assertFalse(OriginReadAuthority::check());}
 public function testStagingLookupRetainsFullPerCheckpointChecks():void{$this->read($this->cap('insert-parent','stage_shares'),static function():void{self::assertFalse(OriginReadAuthority::check());});self::assertSame(0,OriginContext::$reads);}
 public function testUnqualifiedIssuerCannotOpenRead():void{$this->expectException(RuntimeException::class);$this->cap()->readParent(static fn()=>null);}
 public function testEndedTransactionCannotOpenRead():void{\OCP\Server::$transaction=false;$this->expectException(RuntimeException::class);$this->read($this->cap(),static fn()=>null);}
 public function testDifferentJobCannotBorrowRead():void{OriginContext::$match=false;$this->expectException(RuntimeException::class);$this->read($this->cap(),static fn()=>null);}
 public function testExistingClosedReadDoesNotNest():void{OriginReadAuthority::$active=true;self::assertSame(3,$this->read($this->cap(),static fn()=>3));self::assertSame(0,OriginContext::$reads);}
 public function testActivationChildReadUsesItsNamedIssuer():void{self::assertSame(8,(new \OCA\Forms\Mcp\Service\CircleNativeShareWrapperService())->getChild($this->cap('activate-child'),static fn()=>8));self::assertSame(1,OriginContext::$reads);}
 public function testChildCreateCapabilityKeepsFullChecks():void{(new \OCA\Forms\Mcp\Service\CircleNativeShareWrapperService())->getChild($this->cap('insert-child','stage_recipients'),static function():void{self::assertFalse(OriginReadAuthority::check());});self::assertSame(0,OriginContext::$reads);}
 public function testChildScopeCannotBeIssuedDirectly():void{$this->expectException(RuntimeException::class);$this->cap('activate-child')->readChild(static fn()=>null);}
 public function testReadFailureClosesBeforeRethrow():void{$this->expectException(LogicException::class);try{$this->read($this->cap(),static function():never{throw new LogicException();});}finally{self::assertFalse(OriginReadAuthority::check());}}
}
}
