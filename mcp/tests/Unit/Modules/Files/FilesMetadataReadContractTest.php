<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use OCA\Mcp\Mcp\Confirmation\WriteGate;
use PHPUnit\Framework\TestCase;

final class FilesMetadataReadContractTest extends TestCase {
    public function testMetadataReadsDoNotRequireConfirmationAndWritesDo(): void {
        foreach (['description_read', 'tags_read'] as $operation) {
            self::assertFalse(WriteGate::isWrite(['operation' => $operation]), $operation);
        }
        foreach (['description_edit', 'tags_create', 'tags_edit', 'upload_link'] as $operation) {
            self::assertTrue(WriteGate::isWrite(['operation' => $operation]), $operation);
        }
    }
}
