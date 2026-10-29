<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
// Installed only in the isolated, IP-admitted fixture; never in the app package.
header('Content-Type: application/json');
$extensions = ['curl', 'dom', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo_pgsql', 'zip'];
$ok = PHP_SAPI === 'fpm-fcgi' && posix_geteuid() === 1000;
foreach ($extensions as $extension) $ok = $ok && extension_loaded($extension);
http_response_code($ok ? 200 : 500);
echo json_encode(['ok' => $ok, 'phpFpm' => PHP_VERSION, 'sapi' => PHP_SAPI, 'uid' => posix_geteuid(), 'extensions' => $extensions], JSON_THROW_ON_ERROR);
