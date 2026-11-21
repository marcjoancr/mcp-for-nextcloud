<?php
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service {
 final class ClaimReached extends \RuntimeException {}
 final class BulkStore {public function load(string $id):array{throw new ClaimReached();}}
}
namespace {
require_once dirname(__DIR__,3).'/mcp/vendor/autoload.php';
require_once dirname(__DIR__,3).'/mcp/vendor/nextcloud/ocp/OCP/Share/IShare.php';
require_once __DIR__.'/../src/Service/CircleDetached/ShareWriteBoundary.php';
use OCA\Forms\Mcp\Service\{BulkStore,ClaimReached};
use OCA\Forms\Mcp\Service\CircleDetached\ShareWriteBoundary;
final class CircleTransferRecipientClaimTest extends \PHPUnit\Framework\TestCase {
 private function invoke(string $mode,string $recipient):void {
  $r=new ReflectionClass(ShareWriteBoundary::class);$c=$r->newInstanceWithoutConstructor();$r->getConstructor()->invokeArgs($c,[new BulkStore(),['id'=>'test-job'],'share_recipients',1,str_repeat('a',64),1,2,'actor','target',11,'circle',['share_with'=>'single'],$mode,0]);
  $activation=str_starts_with($mode,'activate-');$share=$this->createMock(\OCP\Share\IShare::class);foreach(['getShareType'=>7,'getSharedWith'=>$recipient,'getShareOwner'=>'target','getSharedBy'=>'actor','getNodeId'=>11,'getPermissions'=>$activation?1:0]as$method=>$value)$share->method($method)->willReturn($value);
  $action=match($mode){'insert-parent'=>'insert','insert-child'=>'insert-child','activate-child'=>'update-child'};$c->consume('actor',$action,$share,!$activation,null);
 }
 public function testChildInsertUsesSealedPersonalRecipientBeforeRecheckingClaim():void{$this->expectException(ClaimReached::class);$this->invoke('insert-child','single');}
 public function testCircleCannotReplacePersonalChildRecipient():void{try{$this->invoke('insert-child','circle');self::fail('Wrong recipient admitted');}catch(ClaimReached){self::fail('Wrong recipient reached claim');}catch(RuntimeException $e){self::assertSame('Bulk transfer Circle write differs from its claim',$e->getMessage());}}
 public function testParentStillUsesCircleRecipient():void{$this->expectException(ClaimReached::class);$this->invoke('insert-parent','circle');}
 public function testChildActivationStillUsesBoundParentShare():void{$this->expectException(ClaimReached::class);$this->invoke('activate-child','circle');}
}
}
