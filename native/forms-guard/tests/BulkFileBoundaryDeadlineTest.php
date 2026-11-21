<?php
declare(strict_types=1);
namespace OCP {final class Server {public static object $stage;public static function get(string $class):object{return self::$stage;}}}
namespace {
require_once __DIR__.'/../src/Service/OriginContext.php';
require_once __DIR__.'/../src/Service/BulkDeadline.php';
require_once __DIR__.'/../src/Service/BulkFiles.php';
use OCA\Forms\Mcp\Service\{BulkDeadline,BulkFiles,OriginContext};
final class BulkFileBoundaryDeadlineTest extends \PHPUnit\Framework\TestCase {
 private function boundary(string $tool,float $elapsed,bool $extend):void {
  $context=new ReflectionProperty(OriginContext::class,'current');$start=new ReflectionProperty(BulkDeadline::class,'start');$flag=new ReflectionProperty(BulkDeadline::class,'circleStage');$prior=[$context->getValue(),$start->getValue(),$flag->getValue()];
  try{
   $context->setValue(null,['verified'=>['tool'=>$tool]]);$start->setValue(null,microtime(true)-$elapsed);$flag->setValue(null,false);
   \OCP\Server::$stage=new class($extend){public function __construct(private bool $extend){}public function stage(array &$job,array $row,?callable $boundary):void{if($this->extend)(new ReflectionProperty(BulkDeadline::class,'circleStage'))->setValue(null,true);$boundary('forms_update',[]);}};
   // Exercise the captured time boundary alone; actor/Files policy is outside this test.
   $job=['action'=>'forms_update','phase'=>'stage_shares'];$row=['data'=>[]];$effect=(new ReflectionClass(BulkFiles::class))->newInstanceWithoutConstructor();$effect->effect($job,$row,null);
  }finally{$context->setValue(null,$prior[0]);$start->setValue(null,$prior[1]);$flag->setValue(null,$prior[2]);}
 }
 public function testBoundarySeesExtensionAdmittedAfterItWasCreated():void{$this->boundary('forms_transfer',26,true);self::assertTrue(true);}
 public function testOrdinaryTransferBoundaryStillRejectsAfter25Seconds():void{$this->expectException(RuntimeException::class);$this->boundary('forms_transfer',26,false);}
 public function testExtendedBoundaryStillRejectsAfter45Seconds():void{$this->expectException(RuntimeException::class);$this->boundary('forms_transfer',46,true);}
 public function testOtherOriginsRetain18SecondBoundary():void{$this->expectException(RuntimeException::class);$this->boundary('forms_update',19,true);}
}
}
