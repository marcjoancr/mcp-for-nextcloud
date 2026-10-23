<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

// Static release fixture on container loopback; no PHP source execution or arbitrary filesystem paths.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)
    || !is_string($path) || !preg_match('~\A/(?:manifest\.json|source\.bundle|runtime-dependencies\.tar\.gz|mcp-[0-9]+\.[0-9]+\.[0-9]+\.tar\.gz|BUILDING\.md)\z~', $path)) {
    http_response_code(404); exit;
}
$file = '/tmp/mcp-source-fixture' . $path;
if (!is_file($file) || is_link($file)) { http_response_code(404); exit; }
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($file));
if ($_SERVER['REQUEST_METHOD'] === 'GET') { readfile($file); }
