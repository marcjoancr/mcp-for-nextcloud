<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use OCP\Server;

define('OC_CONSOLE', true);
require '/app/www/public/lib/base.php';

function appStoreFile(bool $create = false): ?\OCP\Files\SimpleFS\ISimpleFile {
    $appData = Server::get(\OC\Files\AppData\Factory::class)->get('appstore');
    $root = $appData->getFolder('/');
    try { return $root->getFile('apps.json'); }
    catch (\OCP\Files\NotFoundException) { return $create ? $root->newFile('apps.json') : null; }
}

try {
    $mode = $argv[1] ?? '';
    $backup = '/tmp/protection-appstore-cache.json';
    if ($mode === 'snapshot') {
        $file = appStoreFile();
        $content = $file?->getContent();
        $state = ['exists' => $file !== null, 'content' => $content === null ? null : base64_encode($content)];
        $bytes = json_encode($state, JSON_THROW_ON_ERROR);
        if (file_put_contents($backup, $bytes) !== strlen($bytes) || !chmod($backup, 0600)) throw new RuntimeException('Cache snapshot write failed');
    } elseif ($mode === 'clear') {
        appStoreFile()?->delete();
    } elseif ($mode === 'restore') {
        $state = json_decode(file_get_contents($backup), true, 16, JSON_THROW_ON_ERROR);
        appStoreFile()?->delete();
        if (($state['exists'] ?? false) === true) {
            if (!is_string($state['content'] ?? null)) throw new RuntimeException('Invalid cache snapshot');
            appStoreFile(true)?->putContent(base64_decode($state['content'], true) ?: '');
        }
    } elseif ($mode !== 'inspect') {
        throw new RuntimeException('Unknown cache operation');
    }

    $file = appStoreFile();
    $content = $file?->getContent();
    $document = is_string($content) ? json_decode($content, true) : null;
    $apps = is_array($document['data'] ?? null) ? $document['data'] : [];
    $mcp = null;
    foreach ($apps as $app) if (($app['id'] ?? null) === 'mcp') { $mcp = $app; break; }
    $result = [
        'mode' => $mode,
        'exists' => $file !== null,
        'sha256' => is_string($content) ? hash('sha256', $content) : null,
        'timestamp' => is_array($document) ? ($document['timestamp'] ?? null) : null,
        'nextcloudVersion' => is_array($document) ? ($document['ncversion'] ?? null) : null,
        'mcpVersions' => is_array($mcp['releases'] ?? null) ? array_values(array_filter(array_column($mcp['releases'], 'version'), 'is_string')) : [],
    ];
    echo 'MCP_PROTECTION_CACHE=' . json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Protection cache operation failed: ' . get_class($error) . "\n");
    exit(1);
}
