<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Integration\FormsGuardIdentity;
use PHPUnit\Framework\TestCase;
final class FormsGuardIdentityTest extends TestCase {
    public function testIndependentPinRejectsSelfAssertedInstalledManifest():void {
        $dir=sys_get_temp_dir().'/forms-pin-'.bin2hex(random_bytes(5));mkdir($dir);
        try {
            file_put_contents($dir.'/mcp-guard-installed.json','{"protocol":1,"sha256":{"Guard.php":"self-asserted"}}');
            self::assertFalse((new FormsGuardIdentity(str_repeat('a',64)))->matches($dir));
            $digest=hash_file('sha256',$dir.'/mcp-guard-installed.json');
            self::assertTrue((new FormsGuardIdentity($digest))->matches($dir));
            file_put_contents($dir.'/mcp-guard-installed.json','{"protocol":1,"sha256":{}}');
            self::assertFalse((new FormsGuardIdentity($digest))->matches($dir));
        } finally {unlink($dir.'/mcp-guard-installed.json');rmdir($dir);}
    }
    public function testMissingPinNeverAdmitsNativeCode():void {
        self::assertFalse((new FormsGuardIdentity(''))->matches('/does/not/exist'));
    }
}
