<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
/** PostgreSQL bytea fields may be streams; compare bytes, never process-local resource IDs. */
function databaseValue(mixed $value): ?string {
    if (is_resource($value)) {
        if (get_resource_type($value) !== 'stream' || !rewind($value)) throw new RuntimeException('Unreadable database stream');
        $value = stream_get_contents($value);
        if ($value === false) throw new RuntimeException('Database stream read failed');
    }
    if ($value !== null && !is_scalar($value)) throw new RuntimeException('Unsupported database value');
    return $value === null ? null : (is_bool($value) ? ($value ? 't' : 'f') : (string)$value);
}
