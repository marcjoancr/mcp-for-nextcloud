<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Integration\{MailNativeBoundary,NativeDraftLinks};
use OCP\{IConfig,IURLGenerator};
use PHPUnit\Framework\TestCase;
final class NativeDraftLinksTest extends TestCase {
    public function testLinksRequireQualifiedSourceAndPrimarySchemaAndNeverPromoteLegacyOrCryptoDrafts():void {
        $verified=false;$version='35.0.2.2';$base='https://cloud.example.test/index.php/apps/mail/';
        $boundary=$this->createMock(MailNativeBoundary::class);$boundary->method('draftsVerified')->willReturnCallback(static function()use(&$verified){return $verified;});
        $urls=$this->createMock(IURLGenerator::class);$urls->method('linkToRouteAbsolute')->willReturnCallback(static function()use(&$base){return $base;});
        $config=$this->createMock(IConfig::class);$config->method('getSystemValueString')->willReturnCallback(static function()use(&$version){return $version;});
        $links=new NativeDraftLinks($boundary,$urls,$config);self::assertNull($links->forDraft(4,new \stdClass()));
        $draft=new class {public bool $only=true;public bool $crypto=false;public function getMcpDraftOnly():bool{return $this->only;}public function getSmimeSign():bool{return $this->crypto;}public function getSmimeEncrypt():bool{return false;}public function isPgpMime():bool{return false;}public function getSendAt():?int{return null;}};
        $verified=true;self::assertSame($base.'?mcpLocalDraft=4',$links->forDraft(4,$draft));
        $draft->only=false;self::assertNull($links->forDraft(4,$draft));$draft->only=true;
        $draft->crypto=true;self::assertNull($links->forDraft(4,$draft));$draft->crypto=false;
        $version='34.0.5';self::assertNull($links->forDraft(4,$draft));$version='35.0.2.2';
        $base='javascript:alert(1)';self::assertNull($links->forDraft(4,$draft));
    }
}
