<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\{Server,IConfig};
final class McpMailCleanHttpTest extends IntegrationTestCase {
    protected function setUp():void {
        parent::setUp();
        if(getenv('MCP_IT_CLEAN_MAIL')!=='1')self::markTestSkipped('Explicit final clean artifact window only.');
        MailCleanHttpActor::assertClean();
        self::assertFalse(Server::get(IConfig::class)->getSystemValueBool('app.mail.mcp-transmission.enabled',false));
    }
    protected function tearDown():void {
        try{self::assertFalse(Server::get(IConfig::class)->getSystemValueBool('app.mail.mcp-transmission.enabled',false));}finally{parent::tearDown();}
    }
    public function testCleanOAuthSendUsesShippingSignedChildAndExactSource():void {
        $r=MailActor::run(MailActor::ALICE,'delivery-native',['scenario'=>'clean_http']);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));
        self::assertTrue($r['http_replayed']);self::assertSame('completed',$r['child']);self::assertSame('accepted',$r['state']);self::assertSame('verified',$r['sent']);self::assertTrue($r['scope_denied']);
    }
    public function testCleanOAuthSnoozeAndWakeUseShippingSignedChildren():void {
        $r=MailActor::run(MailActor::ALICE,'snooze-native',['scenario'=>'clean_http']);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));
        self::assertTrue($r['http_replayed']);self::assertSame('snoozed',$r['placed']);self::assertSame('woken',$r['woken']);self::assertTrue($r['scope_denied']);
    }
    public function testCleanOAuthNonEmittingSieveWorksWithOperatorOff():void {
        $r=MailActor::run(MailActor::ALICE,'sieve-native',['scenario'=>'clean_http']);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));
        self::assertTrue($r['http_replayed']);self::assertSame('completed',$r['state']);self::assertSame('candidate_active',$r['observation']);self::assertTrue($r['read_available']);self::assertTrue($r['emitting_denied']);self::assertTrue($r['scope_denied']);
    }
    public function testCleanOAuthSignatureModePreservesAllOtherColumns():void {
        $r=MailActor::run(MailActor::ALICE,'clean-http-signature');self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));
        self::assertTrue($r['http_replayed']);self::assertTrue($r['changed']);self::assertTrue($r['other_columns_preserved']);
    }
}
