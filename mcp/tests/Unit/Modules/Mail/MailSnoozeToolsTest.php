<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
use OCA\Mcp\Modules\Mail\MailToolCatalog;
use OCA\Mcp\Access\Grants\GrantPolicy;
final class MailSnoozeToolsTest extends TestCase {
    public function testSnoozeActionsRequireFourIndependentGrants():void {
        $defs=MailToolCatalog::definitions();
        foreach(['mail_snooze'=>'snooze','mail_wake'=>'wake','mail_cancel_snooze'=>'cancel_snooze','mail_read_snooze'=>'read_snooze']as$name=>$grant){self::assertArrayHasKey($name,$defs);self::assertSame($grant,$defs[$name]['operation']);self::assertContains($grant,GrantPolicy::CATALOG['mail']);}
    }
}
