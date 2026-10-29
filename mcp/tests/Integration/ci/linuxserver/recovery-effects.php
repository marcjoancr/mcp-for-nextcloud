<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\LinuxServer;

final class RecoveryEffects {
    public static function revocation(array $before, array $after): void {
        $issued = $before['issue_seq']; $revoked = $before['revoked_below'];
        $wasActive = strlen($issued) > strlen($revoked) || (strlen($issued) === strlen($revoked) && strcmp($issued, $revoked) > 0);
        if (!$wasActive || $after['issue_seq'] !== $issued || $after['revoked_below'] !== $issued) throw new \RuntimeException('Post-snapshot revocation did not cut every issued generation');
    }

    public static function dav(string $before, string $desired, string $actual): void {
        if ($before === $desired || $actual !== $desired) throw new \RuntimeException('Post-snapshot DAV mutation did not persist');
    }
}
