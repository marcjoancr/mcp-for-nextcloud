<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailDeliveryAttemptTest extends TestCase {
    private function runner():string {$path=dirname(__DIR__,5).'/native/mail-guard/src/Transmission/DispatchAttempt.php';self::assertFileExists($path,'Single attempt runner must stop on unknown claim commit before constructing transport.');require_once $path;return \OCA\Mail\Transmission\DispatchAttempt::class;}
    public function testUnknownCheckpointCommitNeverConstructsTransport():void {
        $r=$this->runner();$calls=0;$state='queued';
        try{$r::run(function()use(&$state):bool {$state='attempting';throw new \RuntimeException('lost commit acknowledgement');},function()use(&$calls):void {++$calls;},static fn(string $state):bool=>true);self::fail('Unknown claim commit must escape.');}catch(\RuntimeException){}
        self::assertSame('attempting',$state);self::assertSame(0,$calls);
    }
    public function testPartialRecipientOrLostDataAcknowledgementCannotReplay():void {
        $r=$this->runner();foreach(['partial RCPT','lost DATA acknowledgement','transport construction failure'] as $fault){$calls=0;$state='queued';$claim=function()use(&$state):bool {if($state!=='queued')return false;$state='attempting';return true;};$send=function()use(&$calls,&$state,$fault):void {self::assertSame('attempting',$state);++$calls;throw new \RuntimeException($fault);};$finish=function(string $next)use(&$state):bool {$state=$next;return true;};self::assertSame('uncertain',$r::run($claim,$send,$finish));self::assertSame('unchanged',$r::run($claim,$send,$finish));self::assertSame(1,$calls);}
    }
    public function testAcceptancePersistenceFailureRemainsUncertainWithoutResend():void {
        $r=$this->runner();$calls=0;$state='queued';$claim=function()use(&$state):bool {if($state!=='queued')return false;$state='attempting';return true;};$send=function()use(&$calls):void {++$calls;};$finish=function(string $next)use(&$state):bool {if($next==='accepted')throw new \RuntimeException('database lost');$state=$next;return true;};self::assertSame('uncertain',$r::run($claim,$send,$finish));self::assertSame('unchanged',$r::run($claim,$send,$finish));self::assertSame(1,$calls);
    }
    public function testTwoWorkersAndCancellationUseOneCompareAndSwap():void {
        $r=$this->runner();$calls=0;$state='queued';$claim=function()use(&$state):bool {if($state!=='queued')return false;$state='attempting';return true;};$finish=function(string $next)use(&$state):bool {$state=$next;return true;};$send=function()use($r,$claim,$finish,&$calls,&$state):void {++$calls;self::assertSame('unchanged',$r::run($claim,static fn()=>self::fail('second worker submitted'),$finish));self::assertNotSame('queued',$state,'Cancellation can only CAS an unattempted row.');};self::assertSame('accepted',$r::run($claim,$send,$finish));self::assertSame(1,$calls);
    }
}
