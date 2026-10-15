<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service\Policy;

/** Canonical signed-BIGINT counters, without platform-size casts or arithmetic extensions. */
final class DecimalCounter {
    public const MAX = '9223372036854775807';
    public const MAX_EXPECTED = '9223372036854775806';

    public static function validate(string $value, string $maximum = self::MAX_EXPECTED): string {
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value)
            || strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new \InvalidArgumentException('Invalid decimal counter');
        }
        return $value;
    }
}
