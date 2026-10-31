<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
// Mounted only inside the admitted private fixture, at its existing health location.
require '/app/www/public/lib/base.php';
$manager = \OCP\Server::get(\OCP\App\IAppManager::class);
$manager->loadApp('mcp');
$class = (new ReflectionClass(\OCA\Mcp\AppInfo\Application::class))->getFileName();
header('Content-Type: application/json');
echo json_encode(['sapi' => PHP_SAPI, 'uid' => posix_geteuid(), 'class' => realpath($class),
    'sha256' => hash_file('sha256', $class), 'config' => realpath(\OC::$configDir)], JSON_THROW_ON_ERROR);
