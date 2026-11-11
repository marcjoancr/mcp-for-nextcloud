<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailDeliveryStateTest extends TestCase {
    private function state():string {$path=dirname(__DIR__,5).'/native/mail-guard/src/Transmission/DeliveryState.php';self::assertFileExists($path,'A durable delivery state machine must forbid replay.');require_once $path;return \OCA\Mail\Transmission\DeliveryState::class;}
    public function testAttemptCannotBeReplayedOrCancelled():void {
        $s=$this->state();self::assertTrue($s::allows('queued','attempting'));self::assertTrue($s::allows('blocked','cancelled'));
        foreach(['queued','blocked','attempting','cancelled'] as $next){self::assertFalse($s::allows('attempting',$next));self::assertFalse($s::allows('uncertain',$next));self::assertFalse($s::allows('accepted',$next));}
        self::assertTrue($s::allows('attempting','accepted'));self::assertTrue($s::allows('attempting','uncertain'));
    }
    public function testReceiptCannotLeakFrozenContentAndCrashIsUncertain():void {
        $s=$this->state();$row=['id'=>str_repeat('a',64),'draft_id'=>1,'account_id'=>2,'revision'=>str_repeat('b',64),'state'=>'attempting','sent_state'=>'attempting','send_at'=>0,'timezone'=>'UTC','created_at'=>1,'smtp'=>'PRIVATE BODY','artifact'=>'PRIVATE MIME','user_id'=>'private'];
        $receipt=$s::receipt($row);self::assertSame('uncertain',$receipt['state']);self::assertSame('uncertain',$receipt['sentState']);self::assertStringNotContainsString('PRIVATE',json_encode($receipt));self::assertArrayNotHasKey('user_id',$receipt);
    }
    public function testAdmissionIdentityBindsEveryApprovalInput():void {
        $s=$this->state();$args=['alice',1,str_repeat('a',64),'operation',3,'mail_send',0,'UTC'];$first=$s::admission(...$args);
        self::assertNotSame($first,$s::admission(...array_merge($args,[['mailboxId'=>8,'revision'=>str_repeat('c',64)]])));
        foreach([0=>'bob',1=>2,2=>str_repeat('b',64),3=>'other',4=>4,5=>'mail_schedule',6=>2000000000,7=>'Europe/Madrid'] as $i=>$v){$changed=$args;$changed[$i]=$v;self::assertNotSame($first,$s::admission(...$changed));}
    }
}
