<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Access\FormsPrincipal;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;
final class FormsPrincipalTest extends TestCase {
    public function testEachBoundaryRechecksOriginalUidAndRealAdminRequirement():void {
        $seen=[];$gate=new FormsPrincipal(static function(string $uid,bool $admin)use(&$seen):void{$seen[]=[$uid,$admin];});
        $gate->assert('alice',false);$gate->assert('alice',true);
        self::assertSame([['alice',false],['alice',true]],$seen);
    }
    public function testUnavailableOrRevokedNativePrincipalFailsClosedWithoutPrivateDetails():void {
        $gate=new FormsPrincipal(static function():void{throw new \RuntimeException('private storage configuration');});
        try {$gate->assert('alice',true);self::fail('Unknown principal admitted.');}
        catch(ToolFailure $e) {self::assertSame(ToolFailure::FORBIDDEN,$e->getMessage());self::assertStringNotContainsString('private',$e->getMessage());}
    }
}
