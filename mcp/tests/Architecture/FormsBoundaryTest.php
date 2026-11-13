<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;
use OCA\Mcp\Modules\Forms\Execution\FormsCommands;
use PHPUnit\Framework\TestCase;
/** Public tools must not accept private native transfer admission or recovery snapshots. */
final class FormsBoundaryTest extends TestCase {
    public function testPublicSchemasCannotSupplyPrivateNativeFileAuthority(): void {
        foreach (FormsCommands::definitions() as $definition) {
            $schema=json_encode($definition['inputSchema'],JSON_THROW_ON_ERROR);
            foreach (['uploadToken','uploadedFileId','file_manifest','_mcp_origin','stream','mcpHandle'] as $private) {
                self::assertStringNotContainsString('"'.$private.'"',$schema,$definition['name'].' exposes private authority');
            }
        }
    }
}
