<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\LinuxServer;

final class S3Inventory {
    public static function size(mixed $value): int {
        if (is_int($value) && $value >= 0) return $value;
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($integer !== false) return $integer;
        }
        throw new \InvalidArgumentException('Invalid S3 object size');
    }
}
