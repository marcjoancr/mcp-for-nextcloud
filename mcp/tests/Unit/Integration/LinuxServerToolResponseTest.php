<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Integration;

use OCA\Mcp\Tests\Integration\LinuxServer\ToolResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Integration/ci/linuxserver/tool-response.php';

final class LinuxServerToolResponseTest extends TestCase {
    public static function invalidDenials(): iterable {
        yield 'HTTP outage' => [503, null];
        yield 'HTML instead of RPC' => [500, '<html>error</html>'];
        yield 'invalid arguments' => [200, ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32602, 'message' => 'Invalid arguments']]];
        yield 'unexpected tool failure' => [200, ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Unexpected error while accessing Nextcloud.']]]]];
        yield 'different denial' => [200, ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['isError' => true, 'content' => [['type' => 'text', 'text' => 'No access to this resource in Nextcloud.']]]]];
        yield 'wrong request' => [200, ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Resource not found in Nextcloud.']]]]];
    }

    #[DataProvider('invalidDenials')]
    public function testUnexpectedFailureCannotPassAsPolicyDenial(int $status, mixed $body): void {
        $this->expectException(\RuntimeException::class);
        ToolResponse::result(['status' => $status, 'body' => $body], 'Resource not found in Nextcloud.');
    }

    public function testExactRefusalAndValidSuccessRemainDistinct(): void {
        $refusal = ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Resource not found in Nextcloud.']]];
        self::assertSame([], ToolResponse::result(['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => $refusal]], 'Resource not found in Nextcloud.'));
        $success = ['content' => [['type' => 'text', 'text' => 'data']]];
        self::assertSame($success, ToolResponse::result(['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => $success]]));
        $this->expectException(\RuntimeException::class);
        ToolResponse::result(['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => $success]], 'Resource not found in Nextcloud.');
    }
}
