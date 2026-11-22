<?php
declare(strict_types=1);
namespace {
require_once dirname(__DIR__,3).'/mcp/vendor/autoload.php';foreach(['IShare.php','IShareProvider.php','IPartialShareProvider.php','IShareProviderGetUsers.php','IShareProviderSupportsAllSharesInFolder.php']as$file)require_once dirname(__DIR__,3).'/mcp/vendor/nextcloud/ocp/OCP/Share/'.$file;require_once __DIR__.'/../src/Service/CircleBoundaryProvider.php';
use OCA\Forms\Mcp\Service\CircleBoundaryProvider;
final class CircleProviderChildrenTest extends \PHPUnit\Framework\TestCase {
 private int $checks=0;private bool $valid=true;
 private function provider():CircleBoundaryProvider{return new CircleBoundaryProvider('owner',static function():never{throw new LogicException('provider operation must not run');},static function():never{throw new LogicException('write must not run');},function():void{$this->checks++;if(!$this->valid)throw new RuntimeException('revoked');},null);}
 private function share(string $owner='owner',int $type=7,?int $parent=null):\OCP\Share\IShare{$s=$this->createMock(\OCP\Share\IShare::class);foreach(['getId'=>'31','getShareType'=>$type,'getSharedWith'=>'circle','getShareOwner'=>$owner,'getParent'=>$parent]as$method=>$value)$s->method($method)->willReturn($value);return$s;}
 public function testPinnedNativeEmptyChildrenResultHasNoProviderOrWriteOperation():void{self::assertSame([],$this->provider()->getChildren($this->share()));self::assertSame(1,$this->checks);}
 public function testForeignOwnerTypeAndPersonalChildAreDenied():void{foreach([['other',7,null],['owner',1,null],['owner',7,41]]as$args){$denied=false;try{$this->provider()->getChildren($this->share(...$args));}catch(RuntimeException){$denied=true;}self::assertTrue($denied);}}
 public function testRevocationCannotReusePriorEmptyResult():void{$p=$this->provider();$p->getChildren($this->share());$this->valid=false;$this->expectException(RuntimeException::class);try{$p->getChildren($this->share());}finally{self::assertSame(2,$this->checks);}}
 public function testCurrentRootWithExplicitZeroParentIsSupported():void{self::assertSame([],$this->provider()->getChildren($this->share('owner',7,0)));}
}
}
