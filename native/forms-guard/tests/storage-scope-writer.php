<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE', true);
require (getenv('NEXTCLOUD_ROOT') ?: '/var/www/html') . '/lib/base.php';
use OCP\Server;
Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');
$request = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$state = Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state();
Server::get(\OCA\Mcp\Access\ResourceScope\ResourceScopeWriter::class)->setResourceScope(
    $state['revision'], 'admin', $request['uid'], 'files', $request['deny'] ? [] : null);
echo "scope committed\n";
