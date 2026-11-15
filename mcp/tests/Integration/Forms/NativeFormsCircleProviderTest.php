<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class NativeFormsCircleProviderTest extends TestCase {
    public function testInertCircleReadsPreserveCanonicalData(): void {
        if (!getenv('MCP_FORMS_NATIVE_BOUNDARY')) {$this->markTestSkipped('Requires disposable native cell');}
        $process = proc_open([PHP_BINARY, '/tmp/circle-read-invariants.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);$out = stream_get_contents($pipes[1]);$err = stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $err . ' ' . $out);
        self::assertTrue(json_decode($out, true, 64, JSON_THROW_ON_ERROR)['ok'], $out);
    }
    public function testNativeCircleResultsAndDeferredOriginalAuthority(): void {
        if (!getenv('MCP_FORMS_NATIVE_BOUNDARY')) {$this->markTestSkipped('Requires disposable native cell');}
        $process = proc_open([PHP_BINARY, '/tmp/circle-provider-probe.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);$out = stream_get_contents($pipes[1]);$err = stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $err . ' ' . $out);
        self::assertTrue(json_decode($out, true, 64, JSON_THROW_ON_ERROR)['ok'], $out);
    }
}
