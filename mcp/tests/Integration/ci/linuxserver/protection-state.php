<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use OCP\Server;

require '/fixture-probes/state-value.php';
require '/fixture-probes/state-snapshot.php';
define('OC_CONSOLE', true);
require '/app/www/public/lib/base.php';

try {
    $expected = json_decode(file_get_contents('/tmp/protection-state-oracle.json'), true, 512, JSON_THROW_ON_ERROR);
    $actual = snapshot();
    $migrations = $actual['tables']['migrations'] ?? [];
    $policy = array_filter($actual['tables'], static fn ($table, $name): bool =>
        preg_match('/grant|policy|oauth/i', $name) === 1, ARRAY_FILTER_USE_BOTH);
    $summary = [
        'matchesB1' => $actual === $expected,
        'migrationsSha256' => hash('sha256', json_encode($migrations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        'policySha256' => hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        'migrationRows' => count($migrations),
        'policyTables' => array_keys($policy),
    ];
    echo 'MCP_PROTECTION_STATE=' . json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Protection state probe failed: ' . get_class($error) . "\n");
    exit(1);
}
