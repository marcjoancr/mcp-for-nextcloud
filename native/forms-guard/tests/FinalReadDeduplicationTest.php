<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Service/FinalReadSet.php';
use OCA\Forms\Mcp\Service\{FinalReadSet,FinalReadMismatch};
final class FinalReadDeduplicationTest extends \PHPUnit\Framework\TestCase {
 public function testRepeatedIdenticalObservationUsesOneReplayAndBoundedStorage():void{$s=new FinalReadSet(static fn()=>null);$row=['configvalue'=>str_repeat('x',60000)];for($i=0;$i<20;$i++)$s->add('SELECT configvalue FROM *PREFIX*appconfig WHERE appid=?',['forms'],[],[$row]);$s->seal();self::assertCount(1,$s->statement('pgsql')['params']);$s->verify([['q'=>0,'row'=>json_encode($row)]]);self::assertTrue(true);}
 public function testChangedObservationIsRejectedDuringCollection():void{$s=new FinalReadSet(static fn()=>null);$s->add('SELECT enabled FROM *PREFIX*users WHERE uid=?',['actor'],[],[['enabled'=>1]]);$this->expectException(FinalReadMismatch::class);$s->add('SELECT enabled FROM *PREFIX*users WHERE uid=?',['actor'],[],[['enabled'=>0]]);}
 public function testTypedAndDifferentParametersNeverShareObservation():void{$s=new FinalReadSet(static fn()=>null);$sql='SELECT uid FROM *PREFIX*users WHERE uid=?';$s->add($sql,[1],[PDO::PARAM_INT],[['uid'=>'1']]);$s->add($sql,['1'],[PDO::PARAM_STR],[['uid'=>'1']]);$s->seal();self::assertSame([1,'1'],array_values($s->statement('pgsql')['params']));}
 public function testRowOrderCanChangeWhileDuplicateRowsRemainSignificant():void{$s=new FinalReadSet(static fn()=>null);$sql='SELECT uid FROM *PREFIX*users';$rows=[['uid'=>'a'],['uid'=>'a'],['uid'=>'b']];$s->add($sql,[],[],$rows);$s->add($sql,[],[],array_reverse($rows));$s->seal();$s->verify([['q'=>0,'row'=>'{"uid":"a"}'],['q'=>0,'row'=>'{"uid":"b"}'],['q'=>0,'row'=>'{"uid":"a"}']]);self::assertTrue(true);}
 public function testMissingDuplicateRowIsRejectedAtFinalReplay():void{$s=new FinalReadSet(static fn()=>null);$rows=[['uid'=>'a'],['uid'=>'a']];$s->add('SELECT uid FROM *PREFIX*users',[],[],$rows);$s->add('SELECT uid FROM *PREFIX*users',[],[],$rows);$s->seal();$this->expectException(FinalReadMismatch::class);$s->verify([['q'=>0,'row'=>'{"uid":"a"}']]);}
 public function testPhysicalObservationCountRemainsBoundedEvenWithNoRows():void{$s=new FinalReadSet(static fn()=>null);for($i=0;$i<10000;$i++)$s->add('SELECT uid FROM *PREFIX*users',[],[],[]);$this->expectException(RuntimeException::class);$s->add('SELECT uid FROM *PREFIX*users',[],[],[]);}
}
