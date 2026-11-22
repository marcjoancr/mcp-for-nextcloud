<?php
declare(strict_types=1);
namespace OCA\Circles\Model {class FederatedUser {}class ShareWrapper {}}
namespace OCA\Circles\Service {class ShareWrapperService {}}
namespace OCA\Circles\Db {class ShareWrapperRequest {public static ?\Closure $query=null;public function getShareById(int $id,?\OCA\Circles\Model\FederatedUser $user=null):\OCA\Circles\Model\ShareWrapper{return(self::$query)($id,$user);}}}
namespace OCA\Forms\Mcp\Service\CircleDetached {
class ShareWriteBoundary {public static bool $reading=false;public function __construct(public bool $activation=true){}public function activationReadActive():bool{return$this->activation&&self::$reading;}public function readNativeParent(\Closure $read):mixed{if(!$this->activation)return$read();self::$reading=true;try{return$read();}finally{self::$reading=false;}}}
class Lease {public static bool $reading=false;public static int $calls=0;public function metadataRead(\Closure $read):mixed{self::$calls++;self::$reading=true;try{return$read();}finally{self::$reading=false;}}}
}
namespace {
require_once __DIR__.'/../src/Service/CircleDetached/ShareWrapperRequest.php';require_once __DIR__.'/../src/Service/CircleNativeShareWrapperService.php';
use OCA\Forms\Mcp\Service\{CircleNativeShareWrapperService,CircleDetached\ShareWriteBoundary,CircleDetached\ShareWrapperRequest,CircleDetached\Lease};
final class CircleNativeParentModelReadTest extends \PHPUnit\Framework\TestCase {
 protected function setUp():void{ShareWriteBoundary::$reading=false;Lease::$reading=false;Lease::$calls=0;\OCA\Circles\Db\ShareWrapperRequest::$query=static fn()=>new \OCA\Circles\Model\ShareWrapper();}
 protected function tearDown():void{self::assertFalse(ShareWriteBoundary::$reading);self::assertFalse(Lease::$reading);}
 private function wrapper(?ShareWriteBoundary $cap,bool $owned=true):CircleNativeShareWrapperService{$w=(new ReflectionClass(CircleNativeShareWrapperService::class))->newInstanceWithoutConstructor();foreach(['request'=>$owned?new ShareWrapperRequest(new Lease()):new \OCA\Circles\Db\ShareWrapperRequest(),'assertFresh'=>static fn()=>null,'transferCapability'=>$cap]as$key=>$v)(new ReflectionProperty($w,$key))->setValue($w,$v);return$w;}
 public function testNativeParentModelIsHydratedInsideBothClosedReadsAndClosesBeforeReturn():void{$user=new \OCA\Circles\Model\FederatedUser();$model=new \OCA\Circles\Model\ShareWrapper();\OCA\Circles\Db\ShareWrapperRequest::$query=static function(int $id,object $recipient)use($user,$model):object{self::assertSame(31,$id);self::assertSame($user,$recipient);self::assertTrue(ShareWriteBoundary::$reading);self::assertTrue(Lease::$reading);return$model;};self::assertSame($model,$this->wrapper(new ShareWriteBoundary())->getShareById(31,$user));self::assertSame(1,Lease::$calls);}
 public function testOrdinaryAndStagingReadsRetainOriginalPath():void{foreach([null,new ShareWriteBoundary(false)]as$cap){\OCA\Circles\Db\ShareWrapperRequest::$query=static function():object{self::assertFalse(ShareWriteBoundary::$reading);self::assertFalse(Lease::$reading);return new \OCA\Circles\Model\ShareWrapper();};$this->wrapper($cap)->getShareById(31);}self::assertSame(0,Lease::$calls);}
 public function testQueryFailureCannotLeaveAReadFrameActive():void{\OCA\Circles\Db\ShareWrapperRequest::$query=static function():never{throw new LogicException('query');};$this->expectException(LogicException::class);$this->wrapper(new ShareWriteBoundary())->getShareById(31);}
 public function testActivationCannotHydrateWithForeignRequest():void{$this->expectException(RuntimeException::class);$this->wrapper(new ShareWriteBoundary(),false)->getShareById(31);}
 public function testArbitraryCallerCannotUseOwnedModelRead():void{$ran=false;$this->expectException(RuntimeException::class);try{(new ShareWrapperRequest(new Lease()))->readModel(static function()use(&$ran):void{$ran=true;});}finally{self::assertFalse($ran);self::assertSame(0,Lease::$calls);}}
}
}
