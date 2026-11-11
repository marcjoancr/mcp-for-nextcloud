<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\Server;
/** Test-only socket wrapper: authenticates to the synthetic TLS sink normally, then truncates DATA. */
final class MailSmtpShortWriteActor {
    public static function install(): void {
        if (getenv('MCP_IT_DELIVERY_DIRECTORY') !== '/tmp/mcp-mail-delivery-fixture') throw new \RuntimeException('Synthetic sink required.');
        stream_wrapper_register('mcpsmtpwrite', MailSmtpShortStream::class);
        $original = Server::get('OCA\\Mail\\SMTP\\SmtpClientFactory');
        $factory = new class($original) extends \OCA\Mail\SMTP\SmtpClientFactory {
            public function __construct(private object $original) {}
            public function create(\OCA\Mail\Account $account): \Horde_Mail_Transport {
                if ($account->getMailAccount()->getOutboundHost() !== 'localhost' || $account->getMailAccount()->getOutboundPort() !== 33466) throw new \RuntimeException('Synthetic sink identity changed.');
                $transport = $this->original->create($account);
                $smtp = $transport->getSMTPObject();
                $connection = (new \ReflectionProperty($smtp, '_connection'))->getValue($smtp);
                $stream = new \ReflectionProperty($connection, '_stream');
                MailSmtpShortStream::$underlying = $stream->getValue($connection);
                $stream->setValue($connection, fopen('mcpsmtpwrite://sink', 'r+'));
                return $transport;
            }
        };
        (new \ReflectionProperty('OCA\\Mail\\Transmission\\RawSmtp', 'factory'))->setValue(Server::get('OCA\\Mail\\Transmission\\RawSmtp'), $factory);
    }
    public static function proof(): array { return ['closed' => MailSmtpShortStream::$closed, 'faulted' => MailSmtpShortStream::$faulted, 'quit' => MailSmtpShortStream::$quit, 'partialBytes' => MailSmtpShortStream::$partialBytes]; }
}
final class MailSmtpShortStream {
    public mixed $context;
    public static mixed $underlying;
    public static bool $data = false, $faulted = false, $closed = false, $quit = false;
    public static int $partialBytes = 0;
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool { return true; }
    public function stream_write(string $bytes): int|false {
        if (str_starts_with($bytes, 'QUIT')) self::$quit = true;
        if (self::$faulted) return 0;
        if (self::$data) { self::$faulted = true; return self::$partialBytes = fwrite(self::$underlying, substr($bytes, 0, 3)); }
        if (str_ends_with($bytes, "DATA\r\n")) self::$data = true;
        return fwrite(self::$underlying, $bytes);
    }
    public function stream_read(int $count): string|false { return fread(self::$underlying, $count); }
    public function stream_eof(): bool { return feof(self::$underlying); }
    public function stream_stat(): array|false { return fstat(self::$underlying); }
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool { if ($option === STREAM_OPTION_READ_TIMEOUT) return stream_set_timeout(self::$underlying, $arg1, $arg2 ?? 0); return true; }
    public function stream_close(): void { self::$closed = true; if (is_resource(self::$underlying)) fclose(self::$underlying); }
}
