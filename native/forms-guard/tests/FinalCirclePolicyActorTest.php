<?php
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service {final class OriginContext {public static array $context=[];public static function current():array{return self::$context;}}}
namespace {
require_once __DIR__.'/../src/Service/FinalSharePolicyFactory.php';
use OCA\Forms\Mcp\Service\{OriginContext,FinalSharePolicyFactory};
final class FinalCirclePolicyActorTest extends \PHPUnit\Framework\TestCase {
 protected function setUp():void{OriginContext::$context=['action'=>'forms_transfer','verified'=>['tool'=>'forms_transfer','uid'=>'source'],'input'=>['owner_id'=>'target']];}
 private function actor(string $subject):string{return(new ReflectionMethod(FinalSharePolicyFactory::class,'circleReadActor'))->invoke(null,$subject);}
 public function testBothNativePolicySubjectsKeepOriginalReadActor():void{$context=OriginContext::$context;self::assertSame('source',$this->actor('source'));self::assertSame('source',$this->actor('target'));self::assertSame($context,OriginContext::$context);}
 public function testUnrelatedPolicySubjectCannotBorrowActor():void{$this->expectException(RuntimeException::class);$this->actor('other');}
 public function testDifferentToolCannotIssueTransferActor():void{OriginContext::$context['verified']['tool']='forms_restore';$this->expectException(RuntimeException::class);$this->actor('target');}
 public function testMissingOrEmptyVerifiedActorIsDenied():void{foreach([null,'']as$value){OriginContext::$context['verified']['uid']=$value;$denied=false;try{$this->actor('target');}catch(RuntimeException){$denied=true;}self::assertTrue($denied);}}
 public function testMissingDestinationIsDeniedEvenForOriginalSubject():void{unset(OriginContext::$context['input']['owner_id']);$this->expectException(RuntimeException::class);$this->actor('source');}
}
}
