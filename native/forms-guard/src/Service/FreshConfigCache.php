<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;

/** Scoped native config reads go to SQL. Native writes retain shared-cache invalidation. */
final class FreshConfigCache implements \OCP\ICache {
    public function __construct(private ?\OCP\ICache $shared) {}
    public function get(string $key) {return null;}
    public function set(string $key, $value, int $ttl = 0) {return true;}
    public function hasKey(string $key) {return false;}
    public function remove(string $key) {return $this->shared?->remove($key) ?? true;}
    public function clear(string $prefix = '') {return $this->shared?->clear($prefix) ?? true;}
    public static function isAvailable(): bool {return true;}
}
