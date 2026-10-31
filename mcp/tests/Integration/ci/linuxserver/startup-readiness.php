<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Return only fixture predicates and digests. Never serialize the effective configuration.
define('OC_CONSOLE', true);
require '/app/www/public/lib/base.php';
$config = \OCP\Server::get(\OCP\IConfig::class);
$root = '/config/www/nextcloud/config/';
$files = [
    $root . 'config.php', $root . 'fixture.config.php', $root . 'zz-operator.config.php',
    '/config/nginx/nginx.conf', '/config/nginx/ssl.conf', '/config/nginx/site-confs/default.conf',
    '/config/nginx/resolver.conf', '/config/nginx/worker_processes.conf',
];
$hashes = [];
foreach ($files as $file) {
    if (!is_file($file) || !is_readable($file)) throw new RuntimeException('Required fixture configuration unavailable');
    $hashes[basename($file)] = hash_file('sha256', $file);
}
$nginx = file_get_contents('/config/nginx/nginx.conf');
$resolver = file_get_contents('/config/nginx/resolver.conf');
$workers = file_get_contents('/config/nginx/worker_processes.conf');
require '/app/www/src/version.php';
$predicates = [
    'preparedForImage' => $config->getSystemValue('version', '') === implode('.', $OC_Version),
    'migrationMarkerAbsent' => !file_exists($root . 'needs_migration') && !file_exists('/config/www/nextcloud/version.php'),
    'appTreePopulated' => is_file('/app/www/public/apps/mcp/appinfo/info.xml'),
    'shippedConfigLink' => readlink('/app/www/public/config') === rtrim($root, '/'),
    'currentDataPath' => $config->getSystemValue('datadirectory', '') === '/data' && !str_contains(file_get_contents($root . 'config.php'), '/app/www/public/data'),
    'localCacheApcu' => ltrim($config->getSystemValue('memcache.local', ''), '\\') === 'OC\\Memcache\\APCu',
    'lockingCacheApcu' => ltrim($config->getSystemValue('memcache.locking', ''), '\\') === 'OC\\Memcache\\APCu' && $config->getSystemValue('filelocking.enabled', null) === true,
    'webUpgradeDisabled' => $config->getSystemValue('upgrade.disable-web', false) === true,
    'nextcloudConfigReadOnly' => $config->getSystemValue('config_is_read_only', false) === true,
    'nginxIncludesConfFiles' => preg_match('~^\s*include\s+/config/nginx/site-confs/\*\.conf;~m', $nginx) === 1,
    'resolverPrepared' => preg_match('/^\s*resolver\s+[^;]+;/m', $resolver) === 1,
    'workersPrepared' => preg_match('/^\s*worker_processes\s+(auto|[1-9][0-9]*);/m', $workers) === 1,
];
echo json_encode(['hashes' => $hashes, 'predicates' => $predicates], JSON_THROW_ON_ERROR);
