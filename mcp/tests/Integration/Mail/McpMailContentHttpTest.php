<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailContentHttpTest extends IntegrationTestCase {
    public function testOriginalEncryptedEmlExportsThroughActualOAuthFpmAndReplaysWithoutRead():void {$this->proof('service_http_raw',2);}
    public function test25MiBAttachmentSavesDirectlyWhileSmallBase64ToolRefuses():void {$this->proof('service_http_part',3);}
    public function testThreadOnlyOAuthGrantReadsBoundedBodyAndPreservesMaxChars():void {$r=MailActor::run(MailActor::ALICE,'content-native',['mode'=>'service_http_thread']);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));self::assertTrue($r['accepted'],json_encode($r));self::assertTrue($r['message_denied']);self::assertTrue($r['truncated']);self::assertSame(4,$r['total_chars']);self::assertSame(1,$r['attachments']);self::assertSame(1,$r['connections']);self::assertSame(1,$r['bodies']);self::assertSame(0,$r['writes']);}
    public function testFpmAbsoluteDeadlineRefusesSlowDripWithoutPrivateBytes():void {$r=MailActor::run(MailActor::ALICE,'content-native',['mode'=>'service_http_slow_line']);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));self::assertTrue($r['refused']);self::assertTrue($r['private']);self::assertGreaterThanOrEqual(16,$r['elapsed']);self::assertLessThan(20,$r['elapsed']);self::assertSame(1,$r['connections']);self::assertSame(0,$r['writes']);}
    public function testAttachmentOnlyOAuthCannotReadOrSavePrimaryBodyButKeepsAttachmentAccess():void {$r=MailActor::run(MailActor::ALICE,'content-native',['mode'=>'service_http_attachment_only']);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));foreach(['body_read_refused','body_save_refused','body_file_absent','message_denied','export_denied','attachment_read','attachment_saved'] as $key)self::assertTrue($r[$key],$key.': '.json_encode($r));self::assertSame(['2','2','2'],$r['body_sections']);self::assertSame(0,$r['writes']);}
    private function proof(string $mode,int $connections):void {$r=MailActor::run(MailActor::ALICE,'content-native',['mode'=>$mode]);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));foreach(['accepted','integrity','replayed','same_file','private','small_refused','revoked_status','revoked_replay'] as $key)self::assertTrue($r[$key],$key.': '.json_encode($r));self::assertSame('succeeded',$r['status']);self::assertSame($connections,$r['connections']);self::assertSame(0,$r['writes']);}
}
