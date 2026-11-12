<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use PHPUnit\Framework\TestCase;
/** Exercises the installed pinned Horde writer, with deterministic partial/zero stream writes. */
final class NativeSmtpWriteTest extends TestCase {
    public static function limits(): array { return ['partial then zero' => [3], 'zero immediately' => [0], 'failed write' => [-1]]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('limits')]
    public function testIncompleteDataWriteCannotBeAcceptedAsComplete(int $limit): void {
        NativeShortWriteStream::$remaining = $limit;
        NativeShortWriteStream::$bytes = '';
        NativeShortWriteStream::$closed = false;
        $old = ini_set('zend.exception_ignore_args', '0');
        stream_wrapper_register('mcpshort', NativeShortWriteStream::class);
        $stream = fopen('mcpshort://sink', 'w');
        try {
            $writer = new class($stream) extends \Horde_Smtp_Connection {
                public function __construct($stream) { $this->_stream = $stream; $this->_connected = true; $this->_params['debug'] = new class { public function client($value): void {} }; }
            };
            $refused = false;
            try { $writer->write('immutable-message-canary'); } catch (\Horde_Smtp_Exception $error) { $refused = true; self::assertStringNotContainsString('immutable-message-canary', json_encode($error->getTrace(), JSON_THROW_ON_ERROR)); }
            self::assertTrue($refused, 'Positive short/zero writes must interrupt DATA instead of claiming the complete frozen payload was written.');
            self::assertSame(substr('immutable-message-canary', 0, max(0, $limit)), NativeShortWriteStream::$bytes);
            self::assertTrue(NativeShortWriteStream::$closed, 'Close the stream before any caller can append framing or QUIT.');
            self::assertFalse($writer->connected);
        } finally { if (is_resource($stream)) fclose($stream); stream_wrapper_unregister('mcpshort'); ini_set('zend.exception_ignore_args', $old); }
    }
}
final class NativeShortWriteStream {
    public mixed $context;
    public static int $remaining = 0;
    public static string $bytes = '';
    public static bool $closed = false;
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool { return true; }
    public function stream_write(string $bytes): int|false { if (self::$remaining < 0) return false; $written = min(strlen($bytes), self::$remaining); self::$remaining -= $written; self::$bytes .= substr($bytes, 0, $written); return $written; }
    public function stream_close(): void { self::$closed = true; }
    public function stream_stat(): array { return []; }
}
