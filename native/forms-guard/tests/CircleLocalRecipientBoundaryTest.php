<?php
declare(strict_types=1);
namespace OCA\Circles\Model {
 class FederatedUser {public function __construct(private bool $local){}public function getUserId():string{return 'recipient';}public function getUserType():int{return 1;}public function getSingleId():string{return 'single';}public function getInstance():string{return $this->local?'canonical.local':'remote.invalid';}public function isLocal():bool{return$this->local;}}
 class Member {public const TYPE_USER=1;}
 class ShareWrapper {}
}
namespace OCA\Circles\Service {class ShareWrapperService {}}
namespace OCA\Forms\Mcp\Service {
 final class NativeMembershipReached extends \RuntimeException {}
 final class CircleRuntime {public static function assertRecipientMember(string $actor,string $circle,string $recipient,string $single):void{throw new NativeMembershipReached();}}
}
namespace {
 require_once dirname(__DIR__,3).'/mcp/vendor/autoload.php';
 require_once dirname(__DIR__,3).'/mcp/vendor/nextcloud/ocp/OCP/Share/IShare.php';
 require_once __DIR__.'/../src/Service/CircleNativeShareWrapperService.php';
 use OCA\Forms\Mcp\Service\{CircleNativeShareWrapperService,NativeMembershipReached};
 final class CircleLocalRecipientBoundaryTest extends \PHPUnit\Framework\TestCase {
  private function invoke(bool $local):void {
   $w=(new ReflectionClass(CircleNativeShareWrapperService::class))->newInstanceWithoutConstructor();foreach(['assertFresh'=>static function():void{},'actorUid'=>'actor','currentRecipient'=>'recipient']as$key=>$value)(new ReflectionProperty($w,$key))->setValue($w,$value);
   $s=$this->createMock(\OCP\Share\IShare::class);$s->method('getId')->willReturn('11');$s->method('getShareType')->willReturn(7);$s->method('getSharedWith')->willReturn('circle');$w->getChild($s,new \OCA\Circles\Model\FederatedUser($local));
  }
  public function testNativeCanonicalLocalInstanceStillRequiresMembership():void{$this->expectException(NativeMembershipReached::class);$this->invoke(true);}
  public function testRemoteInstanceCannotReachMembershipOrWrite():void{try{$this->invoke(false);self::fail('Remote recipient admitted');}catch(NativeMembershipReached){self::fail('Remote recipient reached membership');}catch(RuntimeException $e){self::assertSame('Native Circle child actor is unqualified',$e->getMessage());}}
 }
}
