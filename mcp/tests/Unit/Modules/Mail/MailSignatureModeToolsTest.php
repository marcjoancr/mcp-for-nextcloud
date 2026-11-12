<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
use OCA\Mcp\Modules\Mail\MailToolCatalog;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
final class MailSignatureModeToolsTest extends TestCase {
    public function testModeIsIndependentlyDeniedAndOnlyNativeEnumsCanBeReviewed():void {
        $defs=MailToolCatalog::definitions();self::assertArrayHasKey('mail_write_signature_mode',$defs);
        $d=$defs['mail_write_signature_mode'];$policy=InMemoryConfig::policy((new InMemoryConfig())->mock($this),new InMemoryOAuthStore());
        $policy->setGrant('alice','mail','write_signatures',true);$policy->setGrant('alice','mail','edit_identities',true);
        self::assertFalse($policy->granted('alice','mail','write_signature_mode'));self::assertTrue(WriteGate::isWrite($d));
        self::assertSame('mail_write_signature_mode',array_key_last($defs));
        self::assertSame([0,1],$d['inputSchema']['properties']['signature_mode']['enum']);
        self::assertArrayNotHasKey('signature',$d['inputSchema']['properties']);self::assertArrayNotHasKey('editor_mode',$d['inputSchema']['properties']);
    }
}
