<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCA\Mcp\Modules\Mail\Integration\{MailServices,NativeDraftLinks,NativeDraftWrites};
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../../stubs/OCA/Mail/Exception/ClientException.php';
require_once dirname(__DIR__,5).'/native/mail-guard/src/Exception/DraftAdmissionFailure.php';
final class NativeDraftWritesTest extends TestCase {
    public function testUnknownNativeSourceNeverResolvesTheNewOptionalService():void {
        $links=$this->createMock(NativeDraftLinks::class);$links->method('available')->willReturn(false);
        $mail=$this->createMock(MailServices::class);$mail->expects(self::never())->method('draftsService');
        $boundary=new NativeDraftWrites($mail,$links);self::assertSame(['legacy'=>true],$boundary->run('alice',static fn():array=>['legacy'=>true]));
    }
    public function testBusyAdmissionIsBeforeAnyStagingCallback():void {
        $links=$this->createMock(NativeDraftLinks::class);$links->method('available')->willReturn(true);
        $coordinator=new class {public function runOwner(string $uid,\Closure $effect):mixed {throw new \OCA\Mail\Exception\DraftAdmissionFailure('Busy');}};
        $mail=$this->createMock(MailServices::class);$mail->method('draftsService')->willReturn(new class($coordinator){public function __construct(private object $guard){}public function draftCoordinator():object{return $this->guard;}});
        $called=false;$boundary=new NativeDraftWrites($mail,$links);
        try{$boundary->run('alice',function()use(&$called):array {$called=true;return [];});self::fail('Busy work was admitted.');}catch(BeforeEffectFailure){self::assertFalse($called);}
    }
    public function testPostAdmissionFailureNeverBecomesSafeToRetry():void {
        $links=$this->createMock(NativeDraftLinks::class);$links->method('available')->willReturn(true);
        $coordinator=new class {public function runOwner(string $uid,\Closure $effect):mixed {$effect(new \stdClass());throw new \RuntimeException('Ownership lost after admission');}};
        $mail=$this->createMock(MailServices::class);$mail->method('draftsService')->willReturn(new class($coordinator){public function __construct(private object $guard){}public function draftCoordinator():object{return $this->guard;}});
        $boundary=new NativeDraftWrites($mail,$links);$called=false;
        try{$boundary->run('alice',function()use(&$called):array {$called=true;return [];});self::fail('Lost ownership accepted.');}catch(\RuntimeException $error){self::assertNotInstanceOf(BeforeEffectFailure::class,$error);self::assertTrue($called);}
    }
}
