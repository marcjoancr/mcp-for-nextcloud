<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Circles\Tools\Traits {
    trait TNCLogger {}
}

namespace OCA\Circles\Model {
    class ModelManager {}

    class Circle {
        public function __construct(private bool $local, private array $members) {}
        public function isLocal(): bool { return $this->local; }
        public function getInheritedMembers(bool $detailed = false, bool $remote = false): array { return $this->members; }
    }

    class Member {
        public const TYPE_USER = 1;
        public const TYPE_CIRCLE = 16;
        public function __construct(private int $type, private bool $local) {}
        public function getUserType(): int { return $this->type; }
        public function isLocal(): bool { return $this->local; }
    }
}

namespace OCA\Forms\Mcp\Service\CircleDetached {
    final class Lease {
        public int $checkpoints = 0;
        public function assertActive(): void {}
        public function checkpoint(): void { $this->checkpoints++; }
    }
    final class Circle extends \OCA\Circles\Model\Circle {}
    final class Member extends \OCA\Circles\Model\Member {}
}

namespace {
    require_once dirname(__DIR__) . '/src/Service/CircleDetached/ModelManager.php';

    final class CircleRemoteInheritedMembersTest extends \PHPUnit\Framework\TestCase {
        private function manager(): array {
            $manager = new \OCA\Forms\Mcp\Service\CircleDetached\ModelManager();
            $lease = new \OCA\Forms\Mcp\Service\CircleDetached\Lease();
            $manager->bindCircleLease($lease);
            return [$manager, $lease];
        }

        public function testLocalCircleWithLocalPersonalMembersSkipsRemoteExpansion(): void {
            [$manager, $lease] = $this->manager();
            $circle = new \OCA\Forms\Mcp\Service\CircleDetached\Circle(true, [
                new \OCA\Forms\Mcp\Service\CircleDetached\Member(1, true),
                new \OCA\Forms\Mcp\Service\CircleDetached\Member(1, true),
            ]);

            $manager->getRemoteInheritedMembers($circle);

            self::assertSame(2, $lease->checkpoints);
        }

        public function testRemoteCircleAndRemoteOrUnknownMembersRemainDenied(): void {
            [$manager] = $this->manager();
            $remoteCircle = new \OCA\Forms\Mcp\Service\CircleDetached\Circle(false, [
                new \OCA\Forms\Mcp\Service\CircleDetached\Member(1, true),
            ]);
            try {
                $manager->getRemoteInheritedMembers($remoteCircle);
                self::fail('Remote Circle identity must remain denied');
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }

            $remoteMemberCircle = new \OCA\Forms\Mcp\Service\CircleDetached\Circle(true, [
                new \OCA\Forms\Mcp\Service\CircleDetached\Member(1, false),
            ]);
            $this->expectException(\RuntimeException::class);
            $manager->getRemoteInheritedMembers($remoteMemberCircle);
        }

        public function testNestedCircleAndOverBudgetMemberSetRemainDenied(): void {
            [$manager] = $this->manager();
            $nestedCircle = new \OCA\Forms\Mcp\Service\CircleDetached\Circle(true, [
                new \OCA\Forms\Mcp\Service\CircleDetached\Member(16, true),
            ]);
            try {
                $manager->getRemoteInheritedMembers($nestedCircle);
                self::fail('Nested Circle membership is outside the local-only profile');
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }

            $members = array_fill(0, 101, new \OCA\Forms\Mcp\Service\CircleDetached\Member(1, true));
            $overBudget = new \OCA\Forms\Mcp\Service\CircleDetached\Circle(true, $members);
            $this->expectException(\RuntimeException::class);
            $manager->getRemoteInheritedMembers($overBudget);
        }
    }
}
