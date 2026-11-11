<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Mail\MailToolCatalog;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
final class MailSieveToolsTest extends TestCase {
    public function testSafeConfigDoesNotGrantExternalEmissionAndEveryNewActionDefaultsDenied():void {
        $defs=MailToolCatalog::definitions();$policy=InMemoryConfig::policy((new InMemoryConfig())->mock($this),new InMemoryOAuthStore());
        foreach(['read_filters'=>false,'write_filters'=>true,'read_absence'=>false,'write_absence'=>true,'disable_absence'=>true,'read_sieve_status'=>false,'transmit_filters'=>true,'enable_absence'=>true]as$op=>$write){self::assertSame($op,$defs['mail_'.$op]['operation']);self::assertFalse($policy->granted('alice','mail',$op));self::assertSame($write,WriteGate::isWrite($defs['mail_'.$op]));}
        $policy->setGrant('alice','mail','write_filters',true);$policy->setGrant('alice','mail','write_absence',true);self::assertFalse($policy->granted('alice','mail','transmit_filters'));self::assertFalse($policy->granted('alice','mail','enable_absence'));
        self::assertNotContains('redirect',$defs['mail_write_filters']['inputSchema']['properties']['filters']['items']['properties']['actions']['items']['properties']['type']['enum']);self::assertContains('redirect',$defs['mail_transmit_filters']['inputSchema']['properties']['filters']['items']['properties']['actions']['items']['properties']['type']['enum']);
        foreach(['mail_write_absence']as$t){self::assertSame([false],$defs[$t]['inputSchema']['properties']['absence']['properties']['enabled']['enum']);self::assertSame([false],$defs[$t]['inputSchema']['properties']['follow_system']['enum']);}
    }
}
