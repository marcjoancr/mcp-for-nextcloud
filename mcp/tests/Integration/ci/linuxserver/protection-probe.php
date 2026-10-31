<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

define('OC_CONSOLE', true);
require '/app/www/public/lib/base.php';

try {
    $manager = \OCP\Server::get(\OCP\App\IAppManager::class);
    $config = \OCP\Server::get(\OCP\IConfig::class);
    $roots = \OC::$APPSROOTS;
    $copies = [];
    foreach ($roots as $root) {
        foreach (glob($root['path'] . '/*/appinfo/info.xml') ?: [] as $info) {
            $bytes = file_get_contents($info);
            if ($bytes === false) throw new RuntimeException('Unreadable app manifest');
            $xml = simplexml_load_string($bytes, options: LIBXML_NONET);
            if ($xml === false) throw new RuntimeException('Invalid app manifest');
            if ((string)$xml->id === 'mcp') {
                $path = realpath(dirname($info, 2));
                if ($path === false) throw new RuntimeException('Unresolved MCP app path');
                $copies[$path] = true;
            }
        }
    }
    $selected = $manager->getAppPath('mcp');
    if (!is_string($selected) || $selected === '' || realpath($selected) === false) {
        throw new RuntimeException('MCP app path unavailable');
    }
    $overrides = [];
    foreach (['appstoreenabled', 'appstoreurl', 'allow_local_remote_servers', 'updater.release.channel'] as $key) {
        $overrides[$key] = $config->getSystemValue($key, null);
    }
    $result = [
        'appstoreenabled' => $config->getSystemValue('appstoreenabled', true),
        'appstoreurl' => $config->getSystemValue('appstoreurl', 'https://apps.nextcloud.com/api/v1'),
        'allow_local_remote_servers' => $config->getSystemValue('allow_local_remote_servers', false),
        'updateChannel' => $config->getSystemValue('updater.release.channel', 'stable'),
        'mcpPath' => realpath($selected),
        'mcpCopies' => array_keys($copies),
        'appsPaths' => array_map(static fn (array $root): array => [
            'path' => $root['path'], 'url' => $root['url'], 'writable' => (bool)($root['writable'] ?? false),
        ], $roots),
        'storeOverrides' => $overrides,
        'mcpPathWritable' => is_writable($selected),
        'mcpParentWritable' => is_writable(dirname($selected)),
    ];
    echo 'MCP_PROTECTION=' . json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Protection probe failed: ' . get_class($error) . "\n");
    exit(1);
}
