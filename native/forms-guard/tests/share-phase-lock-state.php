<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
$db = \OCP\Server::get(\OCP\IDBConnection::class);
$pg = $db->getDatabaseProvider(true) === 'postgres';
$acquired = $pg ? $db->executeQuery('SELECT pg_try_advisory_lock(1179603533, 1)')->fetchOne() : $db->executeQuery('SELECT GET_LOCK(?, 0)', ['forms_mcp_revision_v1'])->fetchOne();
$free = in_array($acquired, [true, 1, '1'], true);
if ($free) {
    $pg ? $db->executeQuery('SELECT pg_advisory_unlock(1179603533, 1)')->fetchOne() : $db->executeQuery('SELECT RELEASE_LOCK(?)', ['forms_mcp_revision_v1'])->fetchOne();
}
echo json_encode(['free' => $free], JSON_THROW_ON_ERROR) . "\n";
