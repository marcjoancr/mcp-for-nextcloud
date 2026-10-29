<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Integration;

use OCA\Mcp\Tests\Integration\LinuxServer\RecoveryEffects;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../Integration/ci/linuxserver/recovery-effects.php';

final class LinuxServerRecoveryEffectsTest extends TestCase {
    public static function incompleteCuts(): iterable {
        yield 'only issued new credentials' => [['issue_seq' => '10', 'revoked_below' => '2'], ['issue_seq' => '12', 'revoked_below' => '2']];
        yield 'partial cut leaves active credential' => [['issue_seq' => '10', 'revoked_below' => '2'], ['issue_seq' => '10', 'revoked_below' => '9']];
        yield 'already revoked before operation' => [['issue_seq' => '10', 'revoked_below' => '10'], ['issue_seq' => '10', 'revoked_below' => '10']];
        yield 'backward cut' => [['issue_seq' => '10', 'revoked_below' => '11'], ['issue_seq' => '10', 'revoked_below' => '10']];
    }

    #[DataProvider('incompleteCuts')]
    public function testIssuanceOrIncompleteRevocationCannotPass(array $before, array $after): void {
        $this->expectException(\RuntimeException::class);
        RecoveryEffects::revocation($before, $after);
    }

    public function testCompleteCutAndExactDavReadbackPass(): void {
        RecoveryEffects::revocation(['issue_seq' => '10', 'revoked_below' => '2'], ['issue_seq' => '10', 'revoked_below' => '10']);
        RecoveryEffects::dav('before', 'after', 'after');
        $this->addToAssertionCount(2);
    }

    public static function incompleteDavWrites(): iterable {
        yield 'write silently ignored' => ['before', 'after', 'before'];
        yield 'unexpected persisted content' => ['before', 'after', 'different'];
        yield 'no state change requested' => ['before', 'before', 'before'];
    }

    #[DataProvider('incompleteDavWrites')]
    public function testDavMutationRequiresActualChangedReadback(string $before, string $desired, string $actual): void {
        $this->expectException(\RuntimeException::class);
        RecoveryEffects::dav($before, $desired, $actual);
    }
}
