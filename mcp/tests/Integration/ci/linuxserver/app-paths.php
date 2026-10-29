<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
/** Core may list symlink aliases of one physical app root; reject distinct trees, not aliases. */
function assertSingleMcpTree(array $directories, string $expected): void {
    $copies = [];
    foreach ($directories as $directory) {
        foreach (glob($directory['path'] . '/*/appinfo/info.xml') as $info) {
            $bytes = file_get_contents($info);
            if ($bytes === false) throw new RuntimeException('Unreadable scanned app manifest');
            $xml = simplexml_load_string($bytes, options: LIBXML_NONET);
            if ($xml === false) throw new RuntimeException('Invalid scanned app manifest');
            if ((string)$xml->id === 'mcp') $copies[realpath(dirname($info, 2))] = true;
        }
    }
    if (array_keys($copies) !== [$expected]) throw new RuntimeException('Duplicate or missing physical MCP app tree');
}
