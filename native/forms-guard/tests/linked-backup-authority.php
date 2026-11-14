<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
use OCP\Server;
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
    $request = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $db = Server::get(\OCP\IDBConnection::class);
    $waited = false;
    if (isset($request['lock_key'])) {
        $db->beginTransaction();
        $row = $db->executeQuery('SELECT id FROM *PREFIX*file_locks WHERE key = ? FOR UPDATE', [$request['lock_key']])->fetchOne();
        if ($row === false) {
            throw new RuntimeException('Fixture lock row is missing');
        }
        echo "LOCKED\n";
        flush();
        $deadline = microtime(true) + 8;
        while (microtime(true) < $deadline) {
            $db->executeQuery('SELECT pg_stat_clear_snapshot()')->fetchOne();
            $waited = $db->executeQuery("SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?", [$request['backend_pid']])->fetchOne() === 'Lock';
            if ($waited) {
                break;
            }
            usleep(10000);
        }
        if (!$waited) {
            throw new RuntimeException('Native Files write never waited on its actual lock row');
        }
    }
    if (isset($request['lock_key'])) {
        // Policy writes require their own top-level transaction, separate from this lock holder.
        $child = $request;
        unset($child['lock_key'], $child['backend_pid']);
        $process = proc_open([PHP_BINARY, __FILE__], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode($child));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || (json_decode($output, true)['committed'] ?? false) !== true) {
            throw new RuntimeException('Independent authority commit failed ' . $error . ' ' . $output);
        }
    } elseif ($request['mode'] === 'connection') {
        $selection = Server::get(\OCA\Mcp\OAuth\ConnectionToolsStore::class);
        $saved = $selection->get($request['uid'], $request['connection']);
        $selection->save($request['uid'], $request['connection'], ['forms_summarize_results'], $saved['revision'], []);
    } else {
        Server::get(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class)->runInternal([$request['uid']], static fn() => null, static function () use ($request): bool {
            Server::get(\OCA\Mcp\Db\ToolGrantsMapper::class)->set('user', $request['uid'], 'forms_link_results_file', $request['allow'] ?? false ? 'allow' : 'deny');
            return true;
        });
    }
    if (isset($request['lock_key'])) {
        $db->commit();
    }
    echo json_encode(['committed' => true, 'waited' => $waited], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}
