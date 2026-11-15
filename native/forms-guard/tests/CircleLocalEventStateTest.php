<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\CircleDetached\LocalEventState;
$source=dirname(__DIR__).'/src/Service/CircleDetached/LocalEventState.php';
if(is_file($source)){require_once $source;}
final class CircleLocalEventStateTest extends TestCase {
    public function testNativeDoneInstanceKeyAndPayloadIdentity():void {
        $state=new LocalEventState(hash('sha256',self::class.$this->name()));self::assertSame(0,$state->status());
        $state->admit(hash('sha256',self::class.$this->name()),['fixture'],static function():void{});$state->claimManage();self::assertSame(1,$state->status());
        $state->done();self::assertSame(8,$state->status());
        $payload=new stdClass();$map=$state->claimResult('local.invalid',$payload);
        self::assertSame(['local.invalid'],$keys=array_keys($map));self::assertSame($payload,$map[$keys[0]]);
        $state->over();self::assertSame(9,$state->status());
    }
    public function testInterruptedManageCannotReplay():void {
        $state=new LocalEventState(hash('sha256',self::class.$this->name()));$state->admit(hash('sha256',self::class.$this->name()),['fixture'],static function():void{});$state->claimManage();
        $this->expectException(LogicException::class);$state->claimManage();
    }
    public function testResultCannotRunBeforeDone():void {
        $state=new LocalEventState(hash('sha256',self::class.$this->name()));$state->admit(hash('sha256',self::class.$this->name()),['fixture'],static function():void{});$state->claimManage();
        $this->expectException(LogicException::class);$state->claimResult('local.invalid',[]);
    }
    public function testInterruptedResultCannotReplay():void {
        $state=new LocalEventState(hash('sha256',self::class.$this->name()));$state->admit(hash('sha256',self::class.$this->name()),['fixture'],static function():void{});$state->claimManage();$state->done();$state->claimResult('local.invalid',[]);
        $this->expectException(LogicException::class);$state->claimResult('local.invalid',[]);
    }
    public function testOverCannotPrecedeResult():void {
        $state=new LocalEventState(hash('sha256',self::class.$this->name()));$state->admit(hash('sha256',self::class.$this->name()),['fixture'],static function():void{});$state->claimManage();$state->done();
        $this->expectException(LogicException::class);$state->over();
    }
    public function testEmptyInstanceIsNotAggregated():void {
        $state=new LocalEventState(hash('sha256',self::class.$this->name()));$state->admit(hash('sha256',self::class.$this->name()),['fixture'],static function():void{});$state->claimManage();$state->done();
        $this->expectException(LogicException::class);$state->claimResult('',[]);
    }
    public function testStateCannotBecomeContinuationProof():void {
        $state=new LocalEventState(hash('sha256',self::class.$this->name()));
        $this->expectException(LogicException::class);serialize($state);
    }
}
