<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailBodyIndexPolicyTest extends TestCase {
    private function policy(array $row):array {$p=dirname(__DIR__,5).'/native/mail-guard/src/Content/BodyIndexPolicy.php';self::assertFileExists($p,'Index coverage must reject truncated native text.');require_once $p;return \OCA\Mail\Content\BodyIndexPolicy::extract($row);}
    public function testCompleteSupportedTextIsExact():void {self::assertSame(['state'=>'complete','text'=>"a & b\n"],$this->policy(['text'=>"a & b\n",'truncated'=>false,'coverage'=>'supported_text_parts_only']));}
    public function testTruncatedAndOversizeTextNeverEnterSearchablePayload():void {foreach([['text'=>'prefix','truncated'=>true],['text'=>str_repeat('é',32769),'truncated'=>false]] as $r){self::assertSame(['state'=>'refused_size','text'=>''],$this->policy($r+['coverage'=>'supported_text_parts_only']));}}
    public function testNulAndUnknownCoverageRefuse():void {foreach([['text'=>"hidden\0text",'coverage'=>'supported_text_parts_only'],['text'=>'text','coverage'=>'unknown']] as $r)self::assertSame(['state'=>'refused_content','text'=>''],$this->policy($r+['truncated'=>false]));}
}
