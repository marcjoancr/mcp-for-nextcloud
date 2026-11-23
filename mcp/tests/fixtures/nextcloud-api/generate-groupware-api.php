<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\FixtureFile;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\SourceParser;

require __DIR__ . '/../../../vendor/autoload.php';
[, $root] = $argv + [null, null];
if (!is_string($root) || !is_dir($root)) {
    throw new InvalidArgumentException('Usage: generate-groupware-api.php PINNED_SOURCE_ROOT');
}
$pins = json_decode((string)file_get_contents(__DIR__ . '/groupware-api-sources.json'), true, 512, JSON_THROW_ON_ERROR);
$classes = [
    'OCP\\Accounts\\IAccountManager',
    'OCP\\Calendar\\Resource\\IResourceMetadata',
    'OCP\\Calendar\\Room\\IRoomMetadata',
    'OCP\\Profile\\IProfileManager',
    'OCA\\DAV\\CalDAV\\ResourceBooking\\ResourcePrincipalBackend',
    'OCA\\DAV\\CalDAV\\ResourceBooking\\RoomPrincipalBackend',
    'OCA\\DAV\\DAV\\Sharing\\Plugin',
];
foreach ($pins as $major => $sources) {
    if (($sources['server'] ?? null) !== ($major . '.0.0')) {
        throw new RuntimeException('Groupware source major mismatch: ' . $major);
    }
    $tree = $root . '/' . $major;
    foreach ($sources['files'] as $path => $source) {
        if (!is_file($tree . '/' . $path) || hash_file('sha256', $tree . '/' . $path) !== $source['sha256']) {
            throw new RuntimeException('Groupware API source mismatch: ' . $major . '/' . $path);
        }
        if ($source['url'] !== 'https://raw.githubusercontent.com/nextcloud/server/v' . $sources['server'] . '/' . $path) {
            throw new RuntimeException('Groupware API source URL mismatch: ' . $major . '/' . $path);
        }
    }
    $file = __DIR__ . '/' . $major . '.php';
    $fixture = require $file;
    $parser = new SourceParser($tree);
    foreach ($classes as $class) {
        $fixture['classes'][$class] = $parser->surface($class) ?? throw new RuntimeException('Pinned source lacks ' . $class . ' in Nextcloud ' . $major);
    }
    ksort($fixture['classes']);
    $fixture['groupwareApiSources'] = $sources;
    $header = explode('return [', (string)file_get_contents($file), 2)[0];
    if (!str_contains($header, 'generate-groupware-api.php')) {
        $header .= "// Groupware API surfaces generated from pinned official server source; URLs and SHA256 in groupware-api-sources.json.\n";
    }
    FixtureFile::write($file, $header, $fixture);
    echo 'Generated groupware API fixture for Nextcloud ' . $major . "\n";
}
