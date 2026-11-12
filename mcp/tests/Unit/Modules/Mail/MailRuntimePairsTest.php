<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Integration\MailRuntimePairs;
use PHPUnit\Framework\TestCase;
final class MailRuntimePairsTest extends TestCase {
    public function testKnownNc35RetainsExactLegacyAndCurrentBuilds():void {
        foreach(['5.12.5','5.12.5.6','5.12.5.7','5.12.5.8','5.12.5.9','5.12.5.10','5.12.5.11','5.12.5.12'] as $mail){
            self::assertTrue(MailRuntimePairs::matches('35.0.2',$mail));
            self::assertTrue(MailRuntimePairs::matches('35.0.2.2',$mail));
        }
    }
    public function testLegacyBuildsCannotAcquireExtendedCoreQualification():void {
        foreach(['33.0.10','33.0.10.1','34.0.5','34.0.5.1'] as $core)
            foreach(['5.12.5','5.12.5.6','5.12.5.7'] as $mail)self::assertFalse(MailRuntimePairs::matches($core,$mail));
    }
    public function testQualifiedNc33NeedsTheExactV8BuildAndPatch():void {
        self::assertTrue(MailRuntimePairs::matches('33.0.10','5.12.5.8'));
        self::assertTrue(MailRuntimePairs::matches('33.0.10.1','5.12.5.8'));
        self::assertFalse(MailRuntimePairs::matches('33.0.9','5.12.5.8'));
        self::assertFalse(MailRuntimePairs::matches('33.0.11','5.12.5.8'));
    }
    public function testQualifiedNc34NeedsTheExactV8BuildAndPatch():void {
        self::assertTrue(MailRuntimePairs::matches('34.0.5','5.12.5.8'));
        self::assertTrue(MailRuntimePairs::matches('34.0.5.1','5.12.5.8'));
        self::assertFalse(MailRuntimePairs::matches('34.0.4','5.12.5.8'));
        self::assertFalse(MailRuntimePairs::matches('34.0.6','5.12.5.8'));
    }
    public function testMalformedUnknownAndFuturePairsStayClosed():void {
        foreach(['','35','35.0','035.0.2','35.00.2','35.0.02','35.0.2.02','35.0.2.2.1','35.0.2-beta','35.0.2 ','35.0.3','36.0.0','33.0.11','34.0.6'] as $core)
            self::assertFalse(MailRuntimePairs::matches($core,'5.12.5.8'),$core);
        foreach(['5.12','5.12.5.13','5.12.5.8-dev','5.13.0'] as $mail)self::assertFalse(MailRuntimePairs::matches('35.0.2',$mail));
    }
}
