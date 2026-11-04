<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Add Talk's native comment reader surface from verified source files. The generic core comment factory can
 * truncate Talk message bodies; this subclass preserves Talk's own text limit in both history directions.
 * Usage: php generate-talk-context.php <31..35> <directory containing the pinned files>
 */
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\FixtureFile;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\NextcloudApiUsage;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\SourceParser;

require __DIR__ . '/../../../vendor/autoload.php';
[, $major, $archives] = $argv + [null, null, null];
$pins = json_decode((string)file_get_contents(__DIR__ . '/talk-context-sources.json'), true, 512, JSON_THROW_ON_ERROR);
if (!isset($pins[$major]) || !is_dir((string)$archives)) {
    fwrite(STDERR, "usage: php generate-talk-context.php <31..35> <verified source directory>\n");
    exit(2);
}
$pin = $pins[$major];
foreach (['server', 'spreed'] as $app) {
    if ($pin[$app] !== NextcloudApiUsage::SOURCES[$major][$app]) {
        throw new RuntimeException('Talk context source version differs from the existing API baseline');
    }
}
$tree = sys_get_temp_dir() . '/mcp-talk-context-' . bin2hex(random_bytes(6));
mkdir($tree);
try {
    foreach ($pin['files'] as $path => $source) {
        $file = $archives . '/' . $source['file'];
        if (!is_file($file) || hash_file('sha256', $file) !== $source['sha256']) {
            throw new RuntimeException('Talk context source SHA256 mismatch: ' . $source['file']);
        }
        if (!is_dir($tree . '/' . dirname($path))) { mkdir($tree . '/' . dirname($path), recursive: true); }
        copy($file, $tree . '/' . $path);
    }
    $fixtureFile = __DIR__ . '/' . $major . '.php';
    $fixture = require $fixtureFile;
    $surface = (new SourceParser($tree))->surface('OCA\\Talk\\Chat\\CommentsManager');
    if (!isset($surface['methods']['getCommentsWithVerbForObjectSinceComment'])) {
        throw new RuntimeException('Official sources lack the native Talk history reader');
    }
    $fixture['classes']['OCA\\Talk\\Chat\\CommentsManager'] = $surface;
    $fixture['talkContextSources'] = $pin;
    $original = (string)file_get_contents($fixtureFile);
    FixtureFile::write($fixtureFile, substr($original, 0, (int)strpos($original, 'return [')), $fixture);
    echo "Generated native Talk context reader surface for NC$major\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($tree);
}
