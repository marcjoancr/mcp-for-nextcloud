<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
$CONFIG = [];
require '/config/www/nextcloud/config/config.php';
if (($CONFIG['dbuser'] ?? null) !== 'oc_admin' || ($CONFIG['dbname'] ?? null) !== 'nextcloud' || ($CONFIG['dbhost'] ?? null) !== 'postgres'
    || ($CONFIG['dbtype'] ?? null) !== 'pgsql' || !is_string($CONFIG['dbpassword'] ?? null) || $CONFIG['dbpassword'] === '' || str_contains($CONFIG['dbpassword'], "\0")) throw new RuntimeException('Unexpected restored database profile');
// PostgreSQL standard_conforming_strings is enabled by the pinned server.
$sql = "CREATE ROLE oc_admin LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD '" . str_replace("'", "''", $CONFIG['dbpassword']) . "';\n";
if (file_put_contents('/tmp/restore-role.sql', $sql) !== strlen($sql) || !chmod('/tmp/restore-role.sql', 0600)) throw new RuntimeException('Private role bootstrap failed');
