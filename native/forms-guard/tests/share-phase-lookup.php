<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
use OCP\Server;
try {
    Server::get(\OCP\App\IAppManager::class)->loadApp('forms');
    Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
    $input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($input['uid']));
    $guard = (new \OCA\Forms\AppInfo\Application())->getContainer()->get(\OCA\Forms\Mcp\Guard::class);
    $receipt = $guard->lookup($input['uid'], $input['key'], $input['origin']);
    $db = Server::get(\OCP\IDBConnection::class);
    $pg = $db->getDatabaseProvider(true) === 'postgres';
    $acquired = $pg ? $db->executeQuery('SELECT pg_try_advisory_lock(1179603533, 1)')->fetchOne() : $db->executeQuery('SELECT GET_LOCK(?, 0)', ['forms_mcp_revision_v1'])->fetchOne();
    $released = in_array($acquired, [true, 1, '1'], true);
    if ($released) {
        $pg ? $db->executeQuery('SELECT pg_advisory_unlock(1179603533, 1)')->fetchOne() : $db->executeQuery('SELECT RELEASE_LOCK(?)', ['forms_mcp_revision_v1'])->fetchOne();
    }
    Server::get(\OCA\Forms\Mcp\Service\ManagedSharePhase::class)->drain();
    echo json_encode(['phase' => $receipt['phases']['files'] ?? null, 'released' => $released], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}
