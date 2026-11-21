<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\FinalReadSet;
require_once __DIR__.'/../src/Service/FinalReadSet.php';
final class FinalReadDiagnosticTest extends TestCase {
 public function testChangedInputStillDeniesWithoutExposingValuesOrParameters():void {$s=new FinalReadSet(static fn()=>null);$s->add('SELECT uid,enabled FROM *PREFIX*users WHERE uid=?',['private-parameter'],[],[['uid'=>'private-user','enabled'=>1]]);$s->seal();try{$s->verify([['q'=>0,'row'=>'{"uid":"private-user","enabled":0}']]);self::fail('Changed input admitted');}catch(RuntimeException$e){self::assertSame('Final read dependency changed',$e->getMessage());self::assertTrue(method_exists($e,'details'));$d=$e->details();self::assertSame(['tables'=>['users'],'columns'=>['enabled'],'expected_rows'=>1,'actual_rows'=>1],$d);self::assertStringNotContainsString('private',json_encode($d));}}
 public function testCorrelationChangeRemainsDeniedEvenWhenColumnMultisetsMatch():void {$s=new FinalReadSet(static fn()=>null);$s->add('SELECT uid,enabled FROM *PREFIX*users',[],[],[['uid'=>'a','enabled'=>1],['uid'=>'b','enabled'=>0]]);$s->seal();try{$s->verify([['q'=>0,'row'=>'{"uid":"a","enabled":0}'],['q'=>0,'row'=>'{"uid":"b","enabled":1}']]);self::fail('Changed rows admitted');}catch(RuntimeException$e){self::assertTrue(method_exists($e,'details'));self::assertSame([], $e->details()['columns']);}}
}
