<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Service/OriginContext.php';
require_once __DIR__.'/../src/Service/BulkDeadline.php';
require_once __DIR__.'/../src/Service/CircleDetached/Lease.php';
use OCA\Forms\Mcp\Service\{BulkDeadline,OriginContext};
use OCA\Forms\Mcp\Service\CircleDetached\Lease;
final class CircleLeaseDeadlineTest extends \PHPUnit\Framework\TestCase {
 private function scoped(?string $tool,bool $flag,Closure $work):void {
  $c=new ReflectionProperty(OriginContext::class,'current');$f=new ReflectionProperty(BulkDeadline::class,'circleStage');$old=[$c->getValue(),$f->getValue()];
  try{$c->setValue(null,$tool===null?null:['verified'=>['tool'=>$tool]]);$f->setValue(null,$flag);$work();}finally{$c->setValue(null,$old[0]);$f->setValue(null,$old[1]);}
 }
 private function lease(int $elapsed):Lease{$l=(new ReflectionClass(Lease::class))->newInstanceWithoutConstructor();(new ReflectionProperty($l,'started'))->setValue($l,hrtime(true)-$elapsed*1000000000);(new ReflectionProperty($l,'maxAgeNanos'))->setValue($l,(new ReflectionMethod(Lease::class,'admittedMaxAge'))->invoke(null));return$l;}
 public function testOnlyQualifiedStagingGrants45Seconds():void{foreach([[null,false,18],['forms_transfer',false,18],['forms_transfer',true,45],['forms_update',true,18]]as[$tool,$flag,$seconds])$this->scoped($tool,$flag,static function()use($seconds):void{self::assertSame($seconds*1000000000,(new ReflectionMethod(Lease::class,'admittedMaxAge'))->invoke(null));});}
 public function testQualifiedStagingLeaseCanFinishSynchronousCallback():void{$this->scoped('forms_transfer',true,function():void{$this->lease(40)->assertActive();self::assertTrue(true);});}
 public function testQualifiedStagingLeaseStillExpires():void{$this->scoped('forms_transfer',true,function():void{$this->expectException(RuntimeException::class);$this->lease(46)->assertActive();});}
 public function testOrdinaryLeaseStillExpiresAt18Seconds():void{$this->scoped('forms_transfer',false,function():void{$this->expectException(RuntimeException::class);$this->lease(19)->assertActive();});}
 public function testClosedQualifiedLeaseCannotRevive():void{$this->scoped('forms_transfer',true,function():void{$l=$this->lease(1);(new ReflectionProperty($l,'active'))->setValue($l,false);$this->expectException(RuntimeException::class);$l->assertActive();});}
}
