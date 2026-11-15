<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Inert raw-data boundary. Never constructs native models or invokes their parsers/getters. */
final class CircleReadData {
    public static function page(array $rows, int $limit = 100): array {
        if ($limit < 1 || $limit > 100 || !array_is_list($rows) || count($rows) > $limit) {throw new \RuntimeException('Circle row budget exceeded');}
        $bytes = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {throw new \RuntimeException('Circle raw row is malformed');}
            self::value($row, 0, $bytes);
        }
        // Check encoded projection only after rejecting objects; no serialization hook can run.
        try {$encoded = json_encode($rows, JSON_THROW_ON_ERROR);} catch (\JsonException) {throw new \RuntimeException('Circle raw row encoding is malformed');}
        if (strlen($encoded) > 1048576) {throw new \RuntimeException('Circle byte budget exceeded');}
        return $rows;
    }
    private static function value(mixed $value, int $depth, int &$bytes): void {
        if ($depth > 32) {throw new \RuntimeException('Circle depth budget exceeded');}
        if (is_object($value) || is_resource($value)) {throw new \RuntimeException('Circle raw data cannot contain an object or resource');}
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $bytes += strlen((string)$key) + 4;
                self::value($child, $depth + 1, $bytes);
                if (is_string($key) && preg_match('/(?:^|_)settings$/D', $key)) {self::settings($child);}
            }
        } elseif (is_string($value)) {$bytes += strlen($value);}
        elseif (is_int($value) || is_bool($value) || $value === null) {$bytes += 24;}
        else {throw new \RuntimeException('Circle raw data type is malformed');}
        if ($bytes > 1048576) {throw new \RuntimeException('Circle byte budget exceeded');}
    }
    private static function settings(mixed $value): void {
        if (!is_string($value)) {throw new \RuntimeException('Circle settings are malformed');}
        try {$settings = json_decode($value, true, 32, JSON_THROW_ON_ERROR);} catch (\JsonException) {throw new \RuntimeException('Circle settings are malformed');}
        if (!is_array($settings)) {throw new \RuntimeException('Circle settings are malformed');}
        if (array_key_exists('password_single', $settings)) {
            if (!is_string($settings['password_single'])) {throw new \RuntimeException('Circle settings are malformed');}
            $length = strlen($settings['password_single']);
            if ($length > 0 && $length < 64) {throw new \RuntimeException('Circle settings require native migration');}
        }
    }
}
