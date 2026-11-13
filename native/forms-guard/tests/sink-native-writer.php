<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
use OCP\Server;
$request = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
Server::get(\OCP\IUserSession::class)->setUser(Server::get(\OCP\IUserManager::class)->get($request['uid']));
$file = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder($request['uid'])->get($request['name']);
$file->putContent($request['content']);
$file->touch($request['mtime']);
echo "native write committed\n";
