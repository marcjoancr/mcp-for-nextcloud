<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
use OCP\Security\ICrypto;
require_once __DIR__.'/../../../stubs/OCA/Mail/Exception/ClientException.php';
require_once dirname(__DIR__,5).'/native/mail-guard/src/Exception/DraftAdmissionFailure.php';
final class MailNativeAuthorizationStateTest extends TestCase {
    private function crypto():ICrypto {
        $c=$this->createMock(ICrypto::class);$key=random_bytes(32);$c->method('calculateHMAC')->willReturnCallback(static fn(string $m):string=>hash_hmac('sha256',$m,$key,true));return $c;
    }
    private function load():void {
        $path=dirname(__DIR__,5).'/native/mail-guard/src/Authorization/StateCodec.php';self::assertFileExists($path);require_once $path;
    }
    public function testSameSecondStatesCannotAliasDifferentManualContexts():void {
        $this->load();$c=$this->crypto();$a=\OCA\Mail\Authorization\StateCodec::create($c,'alice',7,100);$b=\OCA\Mail\Authorization\StateCodec::create($c,'alice',7,100);
        self::assertTrue($a!==$b);self::assertTrue(\OCA\Mail\Authorization\StateCodec::isCorrelated($a));self::assertSame(7,\OCA\Mail\Authorization\StateCodec::validate($c,'alice',$a,100));self::assertSame(7,\OCA\Mail\Authorization\StateCodec::validate($c,'alice',$b,100));
    }
    public function testLegacyStateStillValidatesNativelyButCannotAttestManualContext():void {
        $this->load();$c=$this->crypto();$state='7.100.'.bin2hex($c->calculateHMAC('7.alice.100'));
        self::assertSame(7,\OCA\Mail\Authorization\StateCodec::validate($c,'alice',$state,100));self::assertFalse(\OCA\Mail\Authorization\StateCodec::isCorrelated($state));
    }
    public function testAnotherOwnerCannotConsumeAState():void {
        $this->load();$c=$this->crypto();$a=\OCA\Mail\Authorization\StateCodec::create($c,'alice',7,100);
        $this->expectException(\OCA\Mail\Exception\DraftAdmissionFailure::class);\OCA\Mail\Authorization\StateCodec::validate($c,'bob',$a,100);
    }
    public function testExpiredStateIsRejectedBeforeProviderWork():void {
        $this->load();$c=$this->crypto();$a=\OCA\Mail\Authorization\StateCodec::create($c,'alice',7,100);
        $this->expectException(\OCA\Mail\Exception\DraftAdmissionFailure::class);\OCA\Mail\Authorization\StateCodec::validate($c,'alice',$a,701);
    }
}
