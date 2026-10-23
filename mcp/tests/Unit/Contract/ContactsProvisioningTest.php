<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Contract;

use PHPUnit\Framework\TestCase;

/** The pinned Contacts release is checked before the disposable harness enables it. */
final class ContactsProvisioningTest extends TestCase {
    public function testContactsManifestRequiresIdentityVersionAndCompatibleBounds(): void {
        foreach ([
            ['contacts', '8.9.2', '33', '36', 0],
            ['wrong-app', '8.9.2', '33', '36', 1],
            ['contacts', '8.9.1', '33', '36', 1],
            ['contacts', '8.9.2', '36', '37', 1],
            ['contacts', '8.9.2', '33', '34', 1],
            ['contacts', '8.9.2', '', '36', 1],
            ['contacts', '8.9.2', '33', '', 1],
        ] as [$id, $version, $min, $max, $expected]) {
            $path = tempnam(sys_get_temp_dir(), 'mcp-contacts-manifest-');
            try {
                file_put_contents($path, '<info><id>' . $id . '</id><version>' . $version . '</version><dependencies><nextcloud min-version="' . $min . '" max-version="' . $max . '"/></dependencies></info>');
                $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Integration/ci/check-contacts-manifest.php', $path, '35', '8.9.2'],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame($expected, proc_close($process), $stdout . $stderr);
            } finally {
                unlink($path);
            }
        }
    }
}
