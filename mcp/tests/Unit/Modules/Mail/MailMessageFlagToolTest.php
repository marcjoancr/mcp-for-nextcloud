<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\MailToolCatalog;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use PHPUnit\Framework\TestCase;
final class MailMessageFlagToolTest extends TestCase {
    public function testFlagWriteHasItsOwnPermissionExplicitValueAndConfirmation():void {
        $d=MailToolCatalog::definitions();self::assertArrayHasKey('mail_set_message_flag',$d);
        $flag=$d['mail_set_message_flag'];self::assertSame('set_flags',$flag['operation']);self::assertContains('set_flags',GrantPolicy::CATALOG['mail']);
        self::assertTrue(WriteGate::isWrite($flag));$p=WriteGate::publish($flag);
        self::assertSame(['account_id','message_id','flag','value'],$p['inputSchema']['required']);
        self::assertSame(['seen','flagged'],$p['inputSchema']['properties']['flag']['enum']);self::assertSame('boolean',$p['inputSchema']['properties']['value']['type']);
        self::assertArrayHasKey('confirm',$p['inputSchema']['properties']);self::assertArrayHasKey('mail_move_message',$d);self::assertArrayNotHasKey('mail_send_message',$d);
        foreach(['move','archive','trash','restore'] as $action){$tool=$d['mail_'.$action.'_message'];self::assertSame($action.'_messages',$tool['operation']);self::assertTrue(WriteGate::isWrite($tool));self::assertNotSame('set_flags',$tool['operation']);}
    }
}
