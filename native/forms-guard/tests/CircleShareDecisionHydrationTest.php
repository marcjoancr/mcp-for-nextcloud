<?php
declare(strict_types=1);
namespace OCA\Circles\Model {class ShareWrapper {}}
namespace OCA\Circles\Tools\Traits {trait TArrayTools {}}
namespace OCA\Forms\Mcp\Service\CircleDetached {final class Lease {public function assertActive():void {}}}
namespace {
require_once __DIR__.'/../src/Service/CircleDetached/ShareWrapper.php';
use OCA\Forms\Mcp\Service\CircleDetached\{Lease,ShareWrapper};
final class CircleShareDecisionHydrationTest extends \PHPUnit\Framework\TestCase {
 private function hydrate(array $data,string $prefix=''):ShareWrapper{$m=(new ReflectionClass(ShareWrapper::class))->newInstanceWithoutConstructor();$m->bindCircleLease(new Lease());(new ReflectionMethod($m,'hydrateDecision'))->invoke($m,$data,$prefix);return$m;}
 public function testNativeNullRootRetainsPendingDecision():void{$m=$this->hydrate(['parent'=>null,'accepted'=>'0']);self::assertSame(0,$m->getParent());self::assertSame(0,$m->getStatus());}
 public function testChildAncestryAndRejectionArePreserved():void{$m=$this->hydrate(['parent'=>'19','accepted'=>'2']);self::assertSame(19,$m->getParent());self::assertSame(2,$m->getStatus());}
 public function testPrefixedAcceptedRowKeepsItsDecision():void{$m=$this->hydrate(['s_parent'=>0,'s_accepted'=>1],'s_');self::assertSame(0,$m->getParent());self::assertSame(1,$m->getStatus());}
 public function testMissingConsentCannotBecomeAccepted():void{$this->expectException(RuntimeException::class);$this->hydrate(['parent'=>0]);}
 public function testMalformedConsentCannotBecomeAccepted():void{$this->expectException(RuntimeException::class);$this->hydrate(['parent'=>0,'accepted'=>true]);}
}
}
