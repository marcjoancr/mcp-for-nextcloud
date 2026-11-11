<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Access\ResourceScope;
use OCA\Mcp\Access\Policy\DecimalCounter;
/** Canonical storage identity; typed IDs are confined to Mail. No integer coercion. */
final class ResourceIdentity {
    public static function validate(string $module, string $id): string {
        $number = $id;
        if ($module === 'mail') {
            if (!preg_match('/^(account|mailbox):([1-9][0-9]*)$/D', $id, $parts)) {
                throw new \InvalidArgumentException('Invalid resource identity');
            }
            $number = $parts[2];
        }
        if (DecimalCounter::validate($number, DecimalCounter::MAX) === '0') {
            throw new \InvalidArgumentException('Invalid resource identity');
        }
        return $id;
    }
    public static function valid(string $module, string $id): bool {
        try { self::validate($module, $id); return true; } catch (\InvalidArgumentException) { return false; }
    }
    public static function compare(string $module, string $a, string $b): int {
        self::validate($module, $a); self::validate($module, $b);
        if ($module === 'mail') {
            [$kindA, $a] = explode(':', $a, 2); [$kindB, $b] = explode(':', $b, 2);
            if ($kindA !== $kindB) { return strcmp($kindA, $kindB); }
        }
        return DecimalCounter::compare($a, $b);
    }
}
