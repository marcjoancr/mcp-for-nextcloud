<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

// Disposable loopback-only SMTP recorder. No delivery, DNS lookup or SMTP forwarding exists.
// State contains synthetic fixture payloads only. Authentication arguments are never retained.
if (PHP_SAPI !== 'cli' || count($argv) !== 2 || !is_dir($argv[1])) {
    fwrite(STDERR, "Usage: delivery-server.php PRIVATE_FIXTURE_DIRECTORY\n");
    exit(2);
}
$directory = realpath($argv[1]);
$configPath = $directory . '/mode.json';
$config = json_decode((string)file_get_contents($configPath), true, 16, JSON_THROW_ON_ERROR);
if (($config['fixture'] ?? null) !== 'mcp-synthetic-delivery-v1') {
    throw new RuntimeException('Explicit synthetic fixture identity required.');
}
$port = $config['port'] ?? 33466;
if (!is_int($port) || $port < 1024 || $port > 65535) {
    throw new RuntimeException('Unprivileged loopback port required.');
}
$context = stream_context_create(['ssl' => [
    'local_cert' => $directory . '/certificate.pem',
    'local_pk' => $directory . '/key.pem',
]]);
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    throw new RuntimeException('Synthetic SMTP listener unavailable.');
}
$state = ['fixture' => 'mcp-synthetic-delivery-v1', 'connections' => 0, 'commands' => [], 'data_started' => 0, 'messages' => []];
$persist = static function () use (&$state, $directory): void {
    $temporary = $directory . '/state.pending';
    file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    if (!rename($temporary, $directory . '/state.json')) {
        throw new RuntimeException('Fixture evidence unavailable.');
    }
};
$persist();
while (($client = @stream_socket_accept($server, 3600)) !== false) {
    $config = json_decode((string)file_get_contents($configPath), true, 16, JSON_THROW_ON_ERROR);
    $mode = $config['mode'] ?? 'accept';
    if (!in_array($mode, ['accept', 'reject_rcpt', 'reject_data', 'disconnect_data', 'ack_lost', 'timeout_data'], true)) {
        fclose($client);
        continue;
    }
    ++$state['connections'];
    $persist();
    stream_set_timeout($client, 5);
    if (!@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        fclose($client);
        continue;
    }
    fwrite($client, "220 synthetic.invalid ESMTP\r\n");
    $recipients = 0;
    $authenticated = false;
    while (($line = fgets($client, 8192)) !== false) {
        $command = strtoupper(explode(' ', rtrim($line, "\r\n"), 2)[0]);
        $state['commands'][$command] = ($state['commands'][$command] ?? 0) + 1;
        $persist();
        if ($command === 'EHLO') {
            fwrite($client, "250-synthetic.invalid\r\n250-SIZE 41943040\r\n" . (($config['pipelining'] ?? false) ? "250-PIPELINING\r\n" : '') . "250 AUTH PLAIN LOGIN\r\n");
        } elseif ($command === 'AUTH') {
            $auth = explode(' ', trim(substr($line, 5)), 2);
            if (strtoupper($auth[0]) === 'LOGIN') {
                if (!isset($auth[1])) {
                    fwrite($client, "334 VXNlcm5hbWU6\r\n");
                    fgets($client, 8192);
                }
                fwrite($client, "334 UGFzc3dvcmQ6\r\n");
                fgets($client, 8192);
            } elseif (!isset($auth[1])) {
                fwrite($client, "334 \r\n");
                fgets($client, 8192);
            }
            // A synthetic fixture accepts credentials but never records their value.
            $authenticated = true;
            fwrite($client, "235 2.7.0 synthetic authenticated\r\n");
        } elseif ($command === 'MAIL') {
            $recipients = 0;
            fwrite($client, $authenticated ? "250 2.1.0 sender accepted\r\n" : "530 authenticate\r\n");
        } elseif ($command === 'RCPT') {
            ++$recipients;
            fwrite($client, $mode === 'reject_rcpt' && $recipients > 1 ? "550 5.1.1 synthetic rejection\r\n" : "250 2.1.5 recipient accepted\r\n");
        } elseif ($command === 'DATA') {
            ++$state['data_started'];
            $persist();
            if ($mode === 'reject_data') {
                fwrite($client, "554 5.3.0 synthetic DATA rejected\r\n");
                continue;
            }
            fwrite($client, "354 end with dot\r\n");
            if ($mode === 'disconnect_data') {
                break;
            }
            $bytes = '';
            $complete = false;
            while (($chunk = fgets($client, 65536)) !== false) {
                if ($chunk === ".\r\n") {
                    $complete = true;
                    break;
                }
                if (str_starts_with($chunk, '..')) {
                    $chunk = substr($chunk, 1);
                }
                if (strlen($bytes) + strlen($chunk) > 41943040) {
                    break;
                }
                $bytes .= $chunk;
            }
            if (!$complete) {
                break;
            }
            $id = count($state['messages']) + 1;
            file_put_contents($directory . '/message-' . $id . '.eml', $bytes, LOCK_EX);
            $state['messages'][] = ['id' => $id, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'recipients' => $recipients, 'acknowledged' => $mode === 'accept'];
            $persist();
            if ($mode === 'ack_lost') {
                break;
            }
            if ($mode === 'timeout_data') {
                // Wait only on this isolated client; no real delivery or later acceptance is attempted.
                stream_set_timeout($client, 30);
                while (!feof($client)) {
                    if (fread($client, 1) === false || (stream_get_meta_data($client)['timed_out'] ?? false)) {
                        break;
                    }
                }
                break;
            }
            fwrite($client, "250 2.0.0 synthetic accepted\r\n");
        } elseif ($command === 'RSET') {
            $recipients = 0;
            fwrite($client, "250 reset\r\n");
        } elseif ($command === 'NOOP') {
            fwrite($client, "250 noop\r\n");
        } elseif ($command === 'QUIT') {
            fwrite($client, "221 bye\r\n");
            break;
        } else {
            fwrite($client, "502 unsupported synthetic command\r\n");
        }
    }
    fclose($client);
}
