<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use PHPUnit\Framework\TestCase;
final class NativeGuardContractTest extends TestCase {
    public function testNativeCompanionSourceMustHaveAnExactPinnedManifest(): void {
        $root = dirname(__DIR__, 5) . '/native/forms-guard';
        self::assertFileExists($root . '/manifest.json');
        $manifest = json_decode(file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('5.4.2', $manifest['formsVersion']);
        self::assertSame('066573fd592513a06f8b8624def8244e911efdfd', $manifest['formsCommit']);
        self::assertArrayHasKey('lib/Controller/ApiController.php', $manifest['sourceSha256']);
        self::assertArrayHasKey('lib/Controller/ShareApiController.php', $manifest['sourceSha256']);
    }
}
