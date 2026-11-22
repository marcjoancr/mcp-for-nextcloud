<?php
declare(strict_types=1);
namespace OCA\Circles\Db {class ShareWrapperRequest {}}
namespace OCA\Forms\Mcp\Service\CircleDetached {class Lease {public int $reads=0;public function metadataRead(\Closure $read):mixed{$this->reads++;return$read();}}}
namespace OCA\Forms\Mcp\Service {class CircleNativeShareWrapperService {public function getChildRead(CircleDetached\ShareWrapperRequest $request,\Closure $read):mixed{return$request->readModel($read);}public function getChild(CircleDetached\ShareWrapperRequest $request,\Closure $read):mixed{return$request->readModel($read);}}}
namespace {
require_once __DIR__.'/../src/Service/CircleDetached/ShareWrapperRequest.php';
use OCA\Forms\Mcp\Service\{CircleNativeShareWrapperService,CircleDetached\Lease,CircleDetached\ShareWrapperRequest};
final class CircleModelSourceIssuerTest extends \PHPUnit\Framework\TestCase {
 public function testNamedExistingChildHydrationCanUseSources():void{$lease=new Lease();self::assertSame(42,(new CircleNativeShareWrapperService())->getChildRead(new ShareWrapperRequest($lease),static fn()=>42));self::assertSame(1,$lease->reads);}
 public function testArbitraryCallbackCannotOpenModelSources():void{$ran=false;$this->expectException(RuntimeException::class);try{(new ShareWrapperRequest(new Lease()))->readModel(static function()use(&$ran):void{$ran=true;});}finally{self::assertFalse($ran);}}
 public function testChildCreationEntryCannotOpenModelSources():void{$ran=false;$this->expectException(RuntimeException::class);try{(new CircleNativeShareWrapperService())->getChild(new ShareWrapperRequest(new Lease()),static function()use(&$ran):void{$ran=true;});}finally{self::assertFalse($ran);}}
}
}
