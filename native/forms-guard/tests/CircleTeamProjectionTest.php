<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\CircleDetached\TeamProjection;
$file = dirname(__DIR__) . '/src/Service/CircleDetached/TeamProjection.php';
if (is_file($file)) {
    require_once $file;
}
final class CircleTeamProjectionTest extends TestCase
{
    private function rows(int $n): array
    {
        return array_map(static fn(int $i): array => ['unique_id' => 'team-' . $i], range(1, $n));
    }
    private function consume(array $rows, int &$closed, ?Closure $active = null): array
    {
        $i = 0;
        return TeamProjection::consume(static function () use (&$i, $rows): array|false {
            return $rows[$i++] ?? false;
        }, $active ?? static function (): void {
        }, static function () use (&$closed): void {
            $closed++;
        });
    }
    public function testNativePositiveSizesComplete(): void
    {
        foreach ([100, 101, 106, 1000] as $n) {
            $closed = 0;
            $rows = $this->rows($n);
            self::assertSame(array_column($rows, 'unique_id'), $this->consume($rows, $closed));
            self::assertSame(1, $closed);
        }
    }
    public function testEmptyResultIsEmpty(): void
    {
        $closed = 0;
        self::assertSame([], $this->consume([], $closed));
        self::assertSame(1, $closed);
    }
    public function testNativeOrderDuplicatesAndListPositionsArePreserved(): void
    {
        $closed = 0;
        self::assertSame(['z', 'a', 'z'], $this->consume([['unique_id' => 'z'], ['unique_id' => 'a'], ['unique_id' => 'z']], $closed));
        self::assertSame(1, $closed);
    }
    public function testWholeOverflowRefusalStopsAtSentinel(): void
    {
        $closed = 0;
        $calls = 0;
        $circleDenied23 = false;
        try {
            TeamProjection::consume(static function () use (&$calls): array {
                $calls++;
                return ['unique_id' => 'x'];
            }, static function (): void {
            }, static function () use (&$closed): void {
                $closed++;
            });
        } catch (RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $e;
            }
            $circleDenied23 = true;
            self::assertSame('Circle team projection row budget exceeded', $e->getMessage());
            self::assertSame(1001, $calls);
            self::assertSame(1, $closed);
        }
        self::assertTrue($circleDenied23, 'Overflow silently truncated');
    }
    public function testMalformedTailNeverSilentlyOmitsRow(): void
    {
        foreach ([[], ['unique_id' => ''], ['unique_id' => null], ['unique_id' => 1], ['unique_id' => str_repeat('x', 32)], ['unique_id' => "\xff"], ['unique_id' => 'x', 'extra' => 'x'], ['unique_id' => new stdClass()]] as $circleCase => $bad) {
            $closed = 0;
            $rows = $this->rows(100);
            $rows[] = $bad;
            $circleDenied24 = false;
            try {
                $this->consume($rows, $closed);
            } catch (RuntimeException $circleCaught24) {
                if ($circleCaught24 instanceof \PHPUnit\Framework\AssertionFailedError) {
                    throw $circleCaught24;
                }
                $circleDenied24 = true;
            self::assertSame(['Circle team projection identity is malformed', 'Circle team projection identity is malformed', 'Circle team projection identity is malformed', 'Circle team projection identity is malformed', 'Circle team projection identity is malformed', 'Circle raw row encoding is malformed', 'Circle team projection identity is malformed', 'Circle raw data cannot contain an object or resource'][$circleCase], $circleCaught24->getMessage());
                self::assertSame(1, $closed);
            }
            self::assertTrue($circleDenied24, 'Malformed row accepted');
        }
    }
    public function testShortAndMaximumCharacterNativeIdsAreNotRewritten(): void
    {
        $closed = 0;
        $ids = ['x', str_repeat('x', 31), str_repeat('é', 31)];
        self::assertSame($ids, $this->consume(array_map(static fn(string $id): array => ['unique_id' => $id], $ids), $closed));
    }
    public function testDeadlineBetweenBatchesClosesWithoutPartialReturn(): void
    {
        $closed = 0;
        $calls = 0;
        $circleDenied25 = false;
        try {
            $this->consume($this->rows(106), $closed, static function () use (&$calls): void {
                if (++$calls === 101) {
                    throw new RuntimeException('deadline');
                }
            });
        } catch (RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $e;
            }
            $circleDenied25 = true;
            self::assertSame('deadline', $e->getMessage());
            self::assertSame(101, $calls);
            self::assertSame(1, $closed);
        }
        self::assertTrue($circleDenied25, 'Deadline ignored');
    }
    public function testFetchFailureCloses(): void
    {
        $closed = 0;
        $circleDenied26 = false;
        try {
            TeamProjection::consume(static function (): never {
                throw new RuntimeException('fetch');
            }, static function (): void {
            }, static function () use (&$closed): void {
                $closed++;
            });
        } catch (RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $e;
            }
            $circleDenied26 = true;
            self::assertSame('fetch', $e->getMessage());
            self::assertSame(1, $closed);
        }
        self::assertTrue($circleDenied26, 'Fetch failed open');
    }
    public function testLastAuthorityCheckFailsBeforeReturn(): void
    {
        $closed = 0;
        $calls = 0;
        $circleDenied27 = false;
        try {
            $this->consume([['unique_id' => 'x']], $closed, static function () use (&$calls): void {
                if (++$calls === 3) {
                    throw new RuntimeException('revoked');
                }
            });
        } catch (RuntimeException $e) {
            if ($e instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $e;
            }
            $circleDenied27 = true;
            self::assertSame('revoked', $e->getMessage());
            self::assertSame(1, $closed);
        }
        self::assertTrue($circleDenied27, 'Revocation missed');
    }
    public function testCursorCloseFailureNeverReturnsList(): void
    {
        $this->expectException(RuntimeException::class);
        TeamProjection::consume(static fn(): false => false, static function (): void {
        }, static function (): never {
            throw new RuntimeException('close');
        });
    }
}
