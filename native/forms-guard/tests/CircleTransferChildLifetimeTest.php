<?php
declare(strict_types=1);
namespace OCA\Circles\Model {class ShareWrapper {public function __construct(private object $scope,private string $id){}public function getId():string{if(!$this->scope->active)throw new \RuntimeException('closed');return$this->id;}}}
namespace OCA\Circles {class ShareByCircleProvider {public function __construct(private \OCP\Share\IShare $share){}public function getShareById($id,$recipientId=null):\OCP\Share\IShare{return$this->share;}}}
namespace OCA\Forms\Mcp\Service\CircleDetached {class ShareWriteBoundary {public function assertTransferParent(string $uid,\OCP\Share\IShare $parent):void{}}}
namespace OCA\Forms\Mcp\Service {class CircleNativeShareWrapperService {public function __construct(private \OCA\Circles\Model\ShareWrapper $child){}public function current($parent,$recipient):void{}public function createTransferChild($parent,$source,$recipient,$proof):\OCA\Circles\Model\ShareWrapper{return$this->child;}}}
namespace {
require_once dirname(__DIR__,3).'/mcp/vendor/autoload.php';
foreach(['IShare.php','IShareProvider.php','IPartialShareProvider.php','IShareProviderGetUsers.php','IShareProviderSupportsAllSharesInFolder.php']as$file)require_once dirname(__DIR__,3).'/mcp/vendor/nextcloud/ocp/OCP/Share/'.$file;
require_once __DIR__.'/../src/Service/CircleBoundaryProvider.php';
use OCA\Forms\Mcp\Service\{CircleBoundaryProvider,CircleNativeShareWrapperService};
final class CircleTransferChildLifetimeTest extends \PHPUnit\Framework\TestCase {
 private function invoke(string $id,object $scope):int {
  $parent=$this->createMock(\OCP\Share\IShare::class);foreach(['getId'=>'11','getShareType'=>7,'getParent'=>null,'getSharedWith'=>'circle','getShareOwner'=>'target','getNodeId'=>3]as$method=>$value)$parent->method($method)->willReturn($value);
  $native=new \OCA\Circles\ShareByCircleProvider($parent);$wrapper=new CircleNativeShareWrapperService(new \OCA\Circles\Model\ShareWrapper($scope,$id));
  $run=static function($share,$recipient,$event,$work)use($native,$wrapper,$scope):mixed{try{return$work($native,$wrapper);}finally{$scope->active=false;}};
  $provider=new CircleBoundaryProvider('actor',$run,static function():void{},static function():void{},null,null,new \OCA\Forms\Mcp\Service\CircleDetached\ShareWriteBoundary());
  return$provider->createTransferChild($parent,[],'recipient',static function():void{});
 }
 public function testChildIdentityIsCapturedBeforeProviderLeaseCloses():void{$scope=(object)['active'=>true];self::assertSame(19,$this->invoke('19',$scope));self::assertFalse($scope->active);}
 public function testMalformedChildIdIsRejectedInsideProviderLease():void{$scope=(object)['active'=>true];$this->expectException(\RuntimeException::class);try{$this->invoke('0',$scope);}finally{self::assertFalse($scope->active);}}
}
}
