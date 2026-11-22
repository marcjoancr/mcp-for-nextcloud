<?php
declare(strict_types=1);
namespace OCA\Circles {class ShareByCircleProvider {public function __construct(private \OCP\Share\IShare $share){}public function getShareById($id,$recipient=null):\OCP\Share\IShare{return$this->share;}public function update($share):mixed{return$share;}}}
namespace OCA\Forms\Mcp\Service\CircleDetached {class ShareWriteBoundary {public function readParent(\Closure $read):mixed{return$read();}public function assertTransferParent(string $uid,\OCP\Share\IShare $share):void{}}}
namespace OCA\Forms\Mcp\Service {class CircleNativeShareWrapperService {}}
namespace {
require_once dirname(__DIR__,3).'/mcp/vendor/autoload.php';foreach(['IShare.php','IShareProvider.php','IPartialShareProvider.php','IShareProviderGetUsers.php','IShareProviderSupportsAllSharesInFolder.php']as$file)require_once dirname(__DIR__,3).'/mcp/vendor/nextcloud/ocp/OCP/Share/'.$file;require_once __DIR__.'/../src/Service/CircleBoundaryProvider.php';
use OCA\Forms\Mcp\Service\{CircleBoundaryProvider,CircleNativeShareWrapperService,CircleDetached\ShareWriteBoundary};
final class CircleProviderReadIntentTest extends \PHPUnit\Framework\TestCase {
 private function provider(array &$flags,\OCP\Share\IShare $share):CircleBoundaryProvider{$native=new \OCA\Circles\ShareByCircleProvider($share);$wrapper=new CircleNativeShareWrapperService();$run=static function($s,$r,$e,$work,bool $readOnly=false)use(&$flags,$native,$wrapper):mixed{$flags[]=$readOnly;return$work($native,$wrapper);};return new CircleBoundaryProvider('actor',$run,static fn()=>null,static fn()=>null,null,null,new ShareWriteBoundary());}
 private function share():\OCP\Share\IShare{$share=$this->createMock(\OCP\Share\IShare::class);foreach(['getId'=>'31','getShareType'=>7,'getSharedWith'=>'circle']as$m=>$v)$share->method($m)->willReturn($v);return$share;}
 public function testOnlyNativeParentLookupForwardsReadIntent():void{$flags=[];$share=$this->share();self::assertSame($share,$this->provider($flags,$share)->getShareById(31));self::assertSame([true],$flags);}
 public function testNativeUpdateCannotBorrowReadIntent():void{$flags=[];$share=$this->share();$this->provider($flags,$share)->update($share);self::assertSame([false],$flags);}
}
}
