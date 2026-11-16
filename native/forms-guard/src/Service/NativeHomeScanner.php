<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;

use OCP\Files\Cache\IScanner;

/** Restricts the Home setup scanner to bounded shallow reads before native Core walks a directory. */
final class NativeHomeScanner implements IScanner {
    public function __construct(private IScanner $inner, private NativeHomeStorage $storage) {}

    public function scanFile($file, $reuseExisting = 0, $parentId = -1, $cacheData = null, $lock = true) {
        return $this->storage->scanFile((string)$file, fn(string $path) => $this->inner->scanFile($path, $reuseExisting, $parentId, $cacheData, $lock));
    }

    public function scan($path, $recursive = self::SCAN_RECURSIVE, $reuse = -1, $lock = true) {
        return $this->storage->scan((string)$path, (bool)$recursive, fn(string $relative) => $this->inner->scan($relative, (bool)$recursive, $reuse, $lock));
    }

    public static function isPartialFile($file) {
        return is_string($file) && str_ends_with($file, '.part');
    }

    public function backgroundScan() {
        $this->storage->reject('Background native Home scan is not admitted');
    }
}
