<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\ResourceScope;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\ResourceScope\FilesScope;
use PHPUnit\Framework\TestCase;

final class FilesAccessGuardUnrestrictedTest extends TestCase {
    private function guard(?array $items): FilesAccessGuard {
        $scope = $this->createMock(FilesScope::class);
        $scope->expects(self::once())->method('items')->with('actor')->willReturn($items);
        $guard = (new \ReflectionClass(FilesAccessGuard::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(FilesAccessGuard::class, 'scope');
        $property->setValue($guard, $scope);
        return $guard;
    }

    public function testOnlyExplicitUnrestrictedScopeAdmitsFutureHome(): void {
        $this->guard(null)->assertUnrestrictedScope('actor');
    }

    public function testRestrictedOrEmptyScopeNeverBecomesAllResources(): void {
        foreach ([[], ['42']] as $items) {
            try {
                $this->guard($items)->assertUnrestrictedScope('actor');
                self::fail('Restricted Files scope admitted a future Home');
            } catch (\RuntimeException $error) {
                self::assertSame('Files scope does not include all resources', $error->getMessage());
            }
        }
    }
}
