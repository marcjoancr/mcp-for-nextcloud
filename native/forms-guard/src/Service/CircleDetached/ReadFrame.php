<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
/** A non-persistable synchronous read lifetime; even a retained guard closes on return or exception. */
final class ReadFrame {
    public static function within(\Closure $assertAuthority, \Closure $work): mixed {
        $active = true;
        $guard = static function () use (&$active, $assertAuthority): void {
            if (!$active) {throw new \RuntimeException('Circle read frame ended');}
            $assertAuthority();
        };
        try {$guard();$result=$work($guard);$guard();return $result;} finally {$active = false;}
    }
}
