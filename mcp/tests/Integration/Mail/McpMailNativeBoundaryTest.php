<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailNativeBoundaryTest extends IntegrationTestCase {
    public function testOnlyQualifiedV2AdmitsMovesAndLegacyStillAdmitsFlagsAndTamperAdmitsNeither():void {
        if(getenv('MCP_IT_WITH_MAIL')!=='1')self::markTestSkipped('Mail fixture only');
        $r=MailActor::run(MailActor::ALICE,'c3-boundary');$guard=getenv('MCP_IT_MAIL_GUARD');
        $known=getenv('MCP_IT_NC')==='35'||$guard==='1';
        self::assertSame(in_array($guard,['1','legacy','v2'],true)?'verified':($known?'blocked':'unverified'),$r['before']['capabilities']['flags']['status']);
        self::assertSame(in_array($guard,['1','v2'],true)?'verified':($known?'blocked':'unverified'),$r['before']['capabilities']['move']['status']);
        self::assertSame($guard==='1'?'verified':'unverified',$r['before']['capabilities']['create_mailbox']['status']);
        self::assertSame($known?'blocked':'unverified',$r['tampered']['capabilities']['move']['status']);self::assertSame($known?'blocked':'unverified',$r['tampered']['capabilities']['flags']['status']);self::assertSame($r['before'],$r['restored']);
        self::assertSame('disabled',$r['before']['capabilities']['send']['status']);self::assertTrue($r['no_send']);
    }
}
