<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Contract;

use PHPUnit\Framework\TestCase;

/** The pinned Contacts and Deck releases are checked before the disposable harness enables it. */
final class AppManifestTest extends TestCase {
    public function testManifestRequiresIdentityVersionAndCompatibleBounds(): void {
        foreach (['contacts' => '8.9.2', 'deck' => '1.19.0'] as $appId => $pinned) {
            foreach ([
                [$appId, $pinned, '33', '36', 0],
                ['wrong-app', $pinned, '33', '36', 1],
                [$appId, '0.0.0', '33', '36', 1],
                [$appId, $pinned, '36', '37', 1],
                [$appId, $pinned, '33', '34', 1],
                [$appId, $pinned, '', '36', 1],
                [$appId, $pinned, '33', '', 1],
            ] as [$id, $version, $min, $max, $expected]) {
                $path = tempnam(sys_get_temp_dir(), 'mcp-app-manifest-');
                try {
                    file_put_contents($path, '<info><id>' . $id . '</id><version>' . $version . '</version><dependencies><nextcloud min-version="' . $min . '" max-version="' . $max . '"/></dependencies></info>');
                    $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Integration/ci/check-app-manifest.php', $path, $appId, '35', $pinned],
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

    public function testProvisioningPassesManifestIdentityBeforeMajorAndVersion(): void {
        $harness = file_get_contents(dirname(__DIR__, 2) . '/Integration/ci/harness.sh');
        foreach (['contacts' => ['CONTACTS', '8.9.2'], 'deck' => ['DECK', '1.19.0']] as $appId => [$prefix, $version]) {
            self::assertSame(1, preg_match('/^provision_' . $appId . '\\(\\) \\{\n.*?^\\}/ms', $harness, $match));
            $shell = 'NC=fixture; MCP_IT_NC=35; PINNED=' . escapeshellarg($version) . '; '
                . $prefix . '_TAG=' . escapeshellarg('v' . $version) . '; ' . $prefix . '_SHA=fixture; '
                . 'unpack_release_app() { :; }; '
                . 'docker() { printf \'%s\\n\' "$@"; }; '
                . 'occ() { case "$1" in config:app:get) printf \'%s\' "$PINNED";; esac; }; '
                . $match[0] . "\nprovision_" . $appId;
            $process = proc_open(['sh', '-c', $shell], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr);
            self::assertSame([
                'exec', '-u', 'www-data', 'fixture', 'php',
                '/mcp-it-src/mcp/tests/Integration/ci/check-app-manifest.php',
                '/var/www/html/custom_apps/' . $appId . '/appinfo/info.xml',
                $appId, '35', $version,
            ], explode("\n", rtrim($stdout, "\n")), $appId . ' manifest verifier arguments');
        }
    }
}
