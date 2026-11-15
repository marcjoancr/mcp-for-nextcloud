<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\CircleDetached\UserProjection;
$file=dirname(__DIR__).'/src/Service/CircleDetached/UserProjection.php';if(is_file($file)){require_once$file;}
final class CircleUserProjectionTest extends TestCase {
    private function rows(int $count):array {$rows=[];for($i=0;$i<$count;$i++){$rows[]=['single_id'=>str_pad((string)$i,31,'0',STR_PAD_LEFT),'user_id'=>'fixture-'.$i,'user_type'=>1];}return$rows;}
    private function consume(array $rows,int &$closed,?Closure $checkpoint=null):array {$i=0;return UserProjection::consume(static function()use(&$i,$rows):array|false{return$rows[$i++]??false;},$checkpoint??static function():void{},static function()use(&$closed):void{$closed++;});}
    public function testHundredAndHundredOneCompleteWithoutModelHydration():void {foreach([100,101]as$n){$closed=0;$ids=$this->consume($this->rows($n),$closed);self::assertCount($n,$ids);self::assertSame('fixture-'.($n-1),$ids[$n-1]);self::assertSame(1,$closed);}}
    public function testThousandCompletesAndSentinelCannotTruncate():void {$closed=0;self::assertCount(1000,$this->consume($this->rows(1000),$closed));self::assertSame(1,$closed);$closed=0;try{$this->consume($this->rows(1001),$closed);self::fail('Overflow truncated');}catch(RuntimeException){self::assertSame(1,$closed);}}
    public function testNativeTypeFilterPreservesDuplicatesAndOriginalPositions():void {$rows=$this->rows(4);$rows[0]['user_type']=16;$rows[1]['user_id']='same';$rows[2]['user_type']=2;$rows[3]['user_id']='same';$rows[3]['user_type']='1';$closed=0;self::assertSame([1=>'same',3=>'same'],$this->consume($rows,$closed));self::assertSame(1,$closed);}
    public function testMalformedSelectedIdentityRefusesWholeResultAndCloses():void {$rows=$this->rows(101);$rows[100]['single_id']='';$closed=0;try{$this->consume($rows,$closed);self::fail('Malformed row omitted');}catch(RuntimeException){self::assertSame(1,$closed);}}
    public function testUnknownTypeRefusesRatherThanGuessingRecipient():void {$rows=$this->rows(1);$rows[0]['user_type']=99;$closed=0;$this->expectException(RuntimeException::class);try{$this->consume($rows,$closed);}finally{self::assertSame(1,$closed);}}
    public function testOversizedOrInvalidEncodedUidRefuses():void {foreach([str_repeat('x',257),"\xff"]as$bad){$rows=$this->rows(1);$rows[0]['user_id']=$bad;$closed=0;try{$this->consume($rows,$closed);self::fail('Invalid selected data accepted');}catch(RuntimeException){self::assertSame(1,$closed);}}}
    public function testDeadlineCallbackExceptionClosesAndReturnsNoPartialList():void {$closed=0;$calls=0;try{$this->consume($this->rows(101),$closed,static function()use(&$calls):void{if(++$calls===101){throw new RuntimeException('fixture deadline');}});self::fail('Deadline ignored');}catch(RuntimeException){self::assertSame(1,$closed);self::assertSame(101,$calls);}}
    public function testFetchExceptionAlwaysCloses():void {$closed=0;$this->expectException(RuntimeException::class);try{UserProjection::consume(static function():never{throw new RuntimeException('fixture cursor');},static function():void{},static function()use(&$closed):void{$closed++;});}finally{self::assertSame(1,$closed);}}
    public function testNonUserRowsStillCountTowardTotalBound():void {$rows=$this->rows(1001);foreach($rows as&$row){$row['user_type']=16;}unset($row);$closed=0;$this->expectException(RuntimeException::class);try{$this->consume($rows,$closed);}finally{self::assertSame(1,$closed);}}

    public function testOverflowStopsAtFirstSentinelEvenIfCursorCanReturnMore():void {
        $calls=0;$closed=0;
        try {UserProjection::consume(static function()use(&$calls):array{$calls++;return ['single_id'=>'fixture','user_id'=>'fixture','user_type'=>1];},static function():void{},static function()use(&$closed):void{$closed++;});self::fail('Unbounded cursor accepted');}
        catch(RuntimeException){self::assertSame(1001,$calls);self::assertSame(1,$closed);}
    }
    public function testAggregateEncodingBudgetDoesNotResetBetweenBatches():void {
        $rows=$this->rows(1000);foreach($rows as&$row){$row['user_id']=str_repeat("\x01",256);}unset($row);$closed=0;
        try{$this->consume($rows,$closed);self::fail('Aggregate byte bound reset at batch boundary');}catch(RuntimeException){self::assertSame(1,$closed);}
    }
    public function testMalformedRowAndUnexpectedFieldsRefuseWithoutOmission():void {
        foreach([[],['single_id'=>'x','user_id'=>'x','user_type'=>1,'unexpected'=>'x'],['single_id'=>'x','user_id'=>'','user_type'=>1]]as$row){$closed=0;try{$this->consume([$row],$closed);self::fail('Malformed selected row accepted');}catch(RuntimeException){self::assertSame(1,$closed);}}
    }
}
