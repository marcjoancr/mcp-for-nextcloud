<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\{FinalReadSet,FinalReadWindow};
require_once dirname(__DIR__).'/references/lib__public__DB__IResult.php';
require_once dirname(__DIR__).'/src/FinalReadSet.php';
require_once dirname(__DIR__).'/src/FinalReadWindow.php';
final class FinalReadCursorTest extends TestCase {
 private function fixture(?Closure$deadline=null):array {
  $set=new FinalReadSet($deadline??static function():void{});$set->add('SELECT permissions FROM *PREFIX*filecache WHERE fileid = ?',[1],[],[['permissions'=>5]]);$set->seal();
  $result=$this->createMock(\OCP\DB\IResult::class);$method=new ReflectionMethod(FinalReadWindow::class,'consume');return[$set,$result,$method];
 }
 public function testCursorClosesAfterSuccessfulWholeSnapshot():void {
  [$set,$r,$m]=$this->fixture();$r->method('fetchAssociative')->willReturnOnConsecutiveCalls(['q'=>0,'row'=>'{"permissions":5}'],false);$r->expects(self::once())->method('closeCursor')->willReturn(true);
  $m->invoke(null,$set,$r,0,static function():void{},static fn()=>999999999);self::assertTrue(true);
 }
 public function testDeadlineDuringFetchClosesAndPoisonsTheReadSet():void {
  $expired=false;[$set,$r,$m]=$this->fixture(static function()use(&$expired):void{if($expired)throw new RuntimeException('deadline');});
  $r->method('fetchAssociative')->willReturnCallback(static function()use(&$expired):array{$expired=true;return['q'=>0,'row'=>'{"permissions":5}'];});$r->expects(self::once())->method('closeCursor')->willReturn(true);
  $message=null;try{$m->invoke(null,$set,$r,0,static function():void{},static fn()=>1);}catch(RuntimeException$e){$message=$e->getMessage();}self::assertSame('deadline',$message);
  $expired=false;$this->expectException(RuntimeException::class);$this->expectExceptionMessage('Final read snapshot escaped or repeated');$set->verify([]);
 }
 public function testSlowCompletedStatementNeverAuthorizesEvenWithMatchingData():void {
  [$set,$r,$m]=$this->fixture();$r->method('fetchAssociative')->willReturnOnConsecutiveCalls(['q'=>0,'row'=>'{"permissions":5}'],false);$r->expects(self::once())->method('closeCursor')->willReturn(true);
  $this->expectException(RuntimeException::class);$this->expectExceptionMessage('Final read latency exceeded');$m->invoke(null,$set,$r,0,static function():void{},static fn()=>1000000001);
 }
 public function testTransportFailureClosesCursorAndCannotBecomeRevocationSuccess():void {
  [$set,$r,$m]=$this->fixture();$r->method('fetchAssociative')->willThrowException(new RuntimeException('transport'));$r->expects(self::once())->method('closeCursor')->willReturn(true);
  $this->expectException(RuntimeException::class);$this->expectExceptionMessage('transport');$m->invoke(null,$set,$r,0,static function():void{},static fn()=>1);
 }
 public function testMcpOnlyChangeAfterItsLastReadIsExplicitlyNotProvedByTheCorePacket():void {
  [$set,$r,$m]=$this->fixture();$mcpAllowed=true;$calls=0;$r->method('fetchAssociative')->willReturnCallback(static function()use(&$mcpAllowed,&$calls):array|false{$mcpAllowed=false;return$calls++===0?['q'=>0,'row'=>'{"permissions":5}']:false;});$r->expects(self::once())->method('closeCursor')->willReturn(true);
  $m->invoke(null,$set,$r,0,static function():void{},static fn()=>1);self::assertFalse($mcpAllowed);
  // This is an honest LIMIT of the packet, not a passing authorization qualification.
 }
}
