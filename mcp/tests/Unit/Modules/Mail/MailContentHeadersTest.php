<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailContentHeadersTest extends TestCase {
    protected function setUp():void {$p=dirname(__DIR__,5).'/native/mail-guard/src/Content/BoundedHeaders.php';self::assertFileExists($p);require_once $p;}
    public function testUnfoldedFieldsAndMimeParametersRemainExact():void {$h=\OCA\Mail\Content\BoundedHeaders::parse("From: a@example.test\r\nTo: b@example.test,\r\n c@example.test\r\nContent-Type: text/plain; charset=\"utf-8\"\r\n\r\n");self::assertSame('b@example.test, c@example.test',$h['to']);self::assertSame(['text/plain',['charset'=>'utf-8']],\OCA\Mail\Content\BoundedHeaders::parameters($h['content-type']));}
    public function testDuplicateSecurityAndCompositionFieldsRefuse():void {$this->expectException(\RuntimeException::class);\OCA\Mail\Content\BoundedHeaders::parse("Content-Type: text/plain\r\nContent-Type: application/pkcs7-mime\r\n\r\n");}
    public function testHeaderCountBoundBeforeObjects():void {$this->expectException(\RuntimeException::class);\OCA\Mail\Content\BoundedHeaders::parse(implode("",array_map(static fn($n):string=>"X-".$n.": x\r\n",range(1,65)))."\r\n");}
    public function testHugeFoldedFieldRefused():void {$this->expectException(\RuntimeException::class);\OCA\Mail\Content\BoundedHeaders::parse('Subject: '.str_repeat('a',4096)."\r\n more\r\n\r\n");}
    public function testDuplicateMimeParameterRefusesAmbiguity():void {$this->expectException(\RuntimeException::class);\OCA\Mail\Content\BoundedHeaders::parameters('text/plain; charset=utf-8; charset=ascii');}
}
