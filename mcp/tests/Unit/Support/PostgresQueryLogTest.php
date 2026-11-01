<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

final class PostgresQueryLogTest extends TestCase {
    public function testReadsTheRequestedWindowFromALargeConcurrentLogWithinABoundedMemoryLimit(): void {
        $path = tempnam(sys_get_temp_dir(), 'mcp-pg-log-');
        self::assertNotFalse($path);
        $file = fopen($path, 'wb');
        self::assertIsResource($file);
        try {
            $noise = str_repeat('x', 4096);
            for ($i = 0; $i < 16000; $i++) {
                fwrite($file, "2026-11-01 12:00:00 UTC mcp-it[900] xid=0 LOG: statement: SELECT '$noise';\n");
            }
            fwrite($file, "2026-11-01 12:00:00 UTC mcp-it[42] xid=1 LOG: statement: SELECT 'mcp-it-marker:0000000000000001:begin' AS mcp_it_marker\n");
            fwrite($file, "2026-11-01 12:00:00 UTC mcp-it[900] xid=0 LOG: statement: SELECT 'other session'\n");
            fwrite($file, "2026-11-01 12:00:00 UTC mcp-it[42] xid=1 LOG: statement: SELECT 'inside window'\n");
            fwrite($file, "2026-11-01 12:00:00 UTC mcp-it[42] xid=1 LOG: statement: SELECT 'mcp-it-marker:0000000000000002:end' AS mcp_it_marker\n");
            fclose($file);

            $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
            $code = 'require ' . var_export($autoload, true) . '; '
                . '$log = new \\OCA\\Mcp\\Tests\\Integration\\Support\\PostgresQueryLog($argv[1]); '
                . 'echo json_encode($log->statementsBetween(42, $argv[2], $argv[3]), JSON_THROW_ON_ERROR);';
            $command = escapeshellarg(PHP_BINARY) . ' -d memory_limit=48M -r ' . escapeshellarg($code)
                . ' -- ' . escapeshellarg($path) . ' '
                . escapeshellarg('mcp-it-marker:0000000000000001:begin') . ' '
                . escapeshellarg('mcp-it-marker:0000000000000002:end');
            exec($command, $output, $status);

            self::assertSame(0, $status, implode("\n", $output));
            self::assertSame(["SELECT 'inside window'"], json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            unlink($path);
        }
    }
}
