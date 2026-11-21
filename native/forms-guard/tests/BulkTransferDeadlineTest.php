<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\{BulkDeadline,OriginContext};
use OCA\Forms\Mcp\BeforeEffect;
require_once __DIR__.'/../src/Service/OriginContext.php';
require_once __DIR__.'/../src/BeforeEffect.php';
require_once __DIR__.'/../src/Service/BulkTransferShares.php';
require_once getenv('FORMS_BULK_DEADLINE_SOURCE') ?: __DIR__.'/../src/Service/BulkDeadline.php';

final class BulkTransferDeadlineTest extends TestCase {
    private function context(?string $tool,float $elapsed,Closure $work):void {
        $current=new ReflectionProperty(OriginContext::class,'current');$start=new ReflectionProperty(BulkDeadline::class,'start');
        $priorContext=$current->getValue();$priorStart=$start->getValue();
        try{$current->setValue(null,$tool===null?null:['verified'=>['tool'=>$tool]]);$start->setValue(null,microtime(true)-$elapsed);$work();}
        finally{$current->setValue(null,$priorContext);$start->setValue(null,$priorStart);}
    }
    public function testOnlyTheVerifiedTransferContextUses25Seconds():void {
        foreach([[null,18],['forms_transfer',25],['forms_update',18],['forms_restore',18],['forms_continue_operation',18]]as[$tool,$limit]){
            $this->context($tool,0,static function()use($limit):void{self::assertSame($limit,BulkDeadline::limit());});
        }
    }
    public function testTransferHeadroomRemainsBoundedAndOtherDeadlinesStayUnchanged():void {
        foreach([[null,19,true],['forms_update',19,true],['forms_transfer',19,false],['forms_transfer',26,true]]as[$tool,$elapsed,$expectedDenied]){
            $this->context($tool,$elapsed,static function()use($expectedDenied):void{
                $denied=false;try{BulkDeadline::check();}catch(BeforeEffect){$denied=true;}
                self::assertSame($expectedDenied,$denied);
            });
        }
    }
    private function admit():void {$job=[];$row=['data'=>['live'=>false,'approved_tuple'=>['type'=>7]]];$stage=(new ReflectionClass(\OCA\Forms\Mcp\Service\BulkTransferShares::class))->newInstanceWithoutConstructor();$stage->stage($job,$row,null);}
    public function testOnlyNamedCircleStagingCanExtendAndAlwaysResets():void {
        $this->context('forms_transfer',0,function():void {
            $start=new ReflectionProperty(BulkDeadline::class,'start');$start->setValue(null,null);
            $stop=new RuntimeException('stop');
            try{BulkDeadline::run(function()use($stop):void{$this->admit();self::assertSame(60,BulkDeadline::limit());throw $stop;});}catch(RuntimeException $error){self::assertSame($stop,$error);}
            self::assertSame(25,BulkDeadline::limit());
        });
    }
    public function testExpiredRequestCannotAcquireExtension():void {$this->context('forms_transfer',26,function():void{$this->expectException(BeforeEffect::class);$this->admit();});}
    public function testUnqualifiedCallerCannotAcquireExtension():void {$this->context('forms_transfer',0,function():void{$this->expectException(BeforeEffect::class);BulkDeadline::admitCircleStaging();});}

    public function testCircleExtensionStillRefusesAfter60Seconds():void {
        $this->context('forms_transfer',0,function():void{
            $flag=new ReflectionProperty(BulkDeadline::class,'circleStage');
            try{$this->admit();(new ReflectionProperty(BulkDeadline::class,'start'))->setValue(null,microtime(true)-61);$this->expectException(BeforeEffect::class);BulkDeadline::check();}finally{$flag->setValue(null,false);}
        });
    }

    public function testOtherOriginsCannotAcquireExtension():void {$this->context('forms_update',0,function():void{$this->expectException(BeforeEffect::class);$this->admit();});}

}
