<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Integration;

use OCA\Mcp\Tests\Integration\LinuxServer\S3Inventory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Integration/ci/linuxserver/s3-inventory.php';

final class LinuxServerS3InventoryTest extends TestCase {
    public function testSdkLongStringsAndLocalFileSizesHaveOneExactRepresentation(): void {
        self::assertSame(0, S3Inventory::size('0'));
        self::assertSame(11900000, S3Inventory::size('11900000'));
        self::assertSame(11900000, S3Inventory::size(11900000));
    }

    public function testInvalidAndOverflowingSizesCannotBecomeTruncatedIntegers(): void {
        foreach ([-1, 1.5, '1.5', '-1', '12bytes', '9999999999999999999999999', null] as $value) {
            try { S3Inventory::size($value); self::fail('Invalid S3 size accepted'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
