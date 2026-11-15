<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\CircleDetached\UserProjection;
$file = dirname(__DIR__) . '/src/Service/CircleDetached/UserProjection.php';
if (is_file($file)) {
    require_once $file;
}
final class CircleUserProjectionTest extends TestCase
{
    private function rows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['single_id' => str_pad((string) $i, 31, '0', STR_PAD_LEFT), 'user_id' => 'fixture-' . $i, 'user_type' => 1];
        }
        return $rows;
    }
    private function consume(array $rows, int &$closed, ?Closure $checkpoint = null): array
    {
        $i = 0;
        return UserProjection::consume(static function () use (&$i, $rows): array|false {
            return $rows[$i++] ?? false;
        }, $checkpoint ?? static function (): void {
        }, static function () use (&$closed): void {
            $closed++;
        });
    }
    public function testHundredAndHundredOneCompleteWithoutModelHydration(): void
    {
        foreach ([100, 101] as $n) {
            $closed = 0;
            $ids = $this->consume($this->rows($n), $closed);
            self::assertCount($n, $ids);
            self::assertSame('fixture-' . ($n - 1), $ids[$n - 1]);
            self::assertSame(1, $closed);
        }
    }
    public function testThousandCompletesAndSentinelCannotTruncate(): void
    {
        $closed = 0;
        self::assertCount(1000, $this->consume($this->rows(1000), $closed));
        self::assertSame(1, $closed);
        $closed = 0;
        $circleDenied28 = false;
        try {
            $this->consume($this->rows(1001), $closed);
        } catch (RuntimeException $circleCaught28) {
            if ($circleCaught28 instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $circleCaught28;
            }
            $circleDenied28 = true;
            self::assertSame('Circle user projection row budget exceeded', $circleCaught28->getMessage());
            self::assertSame(1, $closed);
        }
        self::assertTrue($circleDenied28, 'Overflow truncated');
    }
    public function testNativeTypeFilterPreservesDuplicatesAndOriginalPositions(): void
    {
        $rows = $this->rows(4);
        $rows[0]['user_type'] = 16;
        $rows[1]['user_id'] = 'same';
        $rows[2]['user_type'] = 2;
        $rows[3]['user_id'] = 'same';
        $rows[3]['user_type'] = '1';
        $closed = 0;
        self::assertSame([1 => 'same', 3 => 'same'], $this->consume($rows, $closed));
        self::assertSame(1, $closed);
    }
    public function testMalformedSelectedIdentityRefusesWholeResultAndCloses(): void
    {
        $rows = $this->rows(101);
        $rows[100]['single_id'] = '';
        $closed = 0;
        $circleDenied29 = false;
        try {
            $this->consume($rows, $closed);
        } catch (RuntimeException $circleCaught29) {
            if ($circleCaught29 instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $circleCaught29;
            }
            $circleDenied29 = true;
            self::assertSame('Circle user projection identity is malformed', $circleCaught29->getMessage());
            self::assertSame(1, $closed);
        }
        self::assertTrue($circleDenied29, 'Malformed row omitted');
    }
    public function testUnknownTypeRefusesRatherThanGuessingRecipient(): void
    {
        $rows = $this->rows(1);
        $rows[0]['user_type'] = 99;
        $closed = 0;
        $this->expectException(RuntimeException::class);
        try {
            $this->consume($rows, $closed);
        } finally {
            self::assertSame(1, $closed);
        }
    }
    public function testOversizedOrInvalidEncodedUidRefuses(): void
    {
        foreach ([str_repeat('x', 257), "\xff"] as $circleCase => $bad) {
            $rows = $this->rows(1);
            $rows[0]['user_id'] = $bad;
            $closed = 0;
            $circleDenied30 = false;
            try {
                $this->consume($rows, $closed);
            } catch (RuntimeException $circleCaught30) {
                if ($circleCaught30 instanceof \PHPUnit\Framework\AssertionFailedError) {
                    throw $circleCaught30;
                }
                $circleDenied30 = true;
            self::assertSame(['Circle user projection identity is malformed', 'Circle raw row encoding is malformed'][$circleCase], $circleCaught30->getMessage());
                self::assertSame(1, $closed);
            }
            self::assertTrue($circleDenied30, 'Invalid selected data accepted');
        }
    }
    public function testDeadlineCallbackExceptionClosesAndReturnsNoPartialList(): void
    {
        $closed = 0;
        $calls = 0;
        $circleDenied31 = false;
        try {
            $this->consume($this->rows(101), $closed, static function () use (&$calls): void {
                if (++$calls === 101) {
                    throw new RuntimeException('fixture deadline');
                }
            });
        } catch (RuntimeException $circleCaught31) {
            if ($circleCaught31 instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $circleCaught31;
            }
            $circleDenied31 = true;
            self::assertSame('fixture deadline', $circleCaught31->getMessage());
            self::assertSame(1, $closed);
            self::assertSame(101, $calls);
        }
        self::assertTrue($circleDenied31, 'Deadline ignored');
    }
    public function testFetchExceptionAlwaysCloses(): void
    {
        $closed = 0;
        $this->expectException(RuntimeException::class);
        try {
            UserProjection::consume(static function (): never {
                throw new RuntimeException('fixture cursor');
            }, static function (): void {
            }, static function () use (&$closed): void {
                $closed++;
            });
        } finally {
            self::assertSame(1, $closed);
        }
    }
    public function testNonUserRowsStillCountTowardTotalBound(): void
    {
        $rows = $this->rows(1001);
        foreach ($rows as &$row) {
            $row['user_type'] = 16;
        }
        unset($row);
        $closed = 0;
        $this->expectException(RuntimeException::class);
        try {
            $this->consume($rows, $closed);
        } finally {
            self::assertSame(1, $closed);
        }
    }
    public function testOverflowStopsAtFirstSentinelEvenIfCursorCanReturnMore(): void
    {
        $calls = 0;
        $closed = 0;
        $circleDenied32 = false;
        try {
            UserProjection::consume(static function () use (&$calls): array {
                $calls++;
                return ['single_id' => 'fixture', 'user_id' => 'fixture', 'user_type' => 1];
            }, static function (): void {
            }, static function () use (&$closed): void {
                $closed++;
            });
        } catch (RuntimeException $circleCaught32) {
            if ($circleCaught32 instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $circleCaught32;
            }
            $circleDenied32 = true;
            self::assertSame('Circle user projection row budget exceeded', $circleCaught32->getMessage());
            self::assertSame(1001, $calls);
            self::assertSame(1, $closed);
        }
        self::assertTrue($circleDenied32, 'Unbounded cursor accepted');
    }
    public function testAggregateEncodingBudgetDoesNotResetBetweenBatches(): void
    {
        $rows = $this->rows(1000);
        foreach ($rows as &$row) {
            $row['user_id'] = str_repeat("\x01", 256);
        }
        unset($row);
        $closed = 0;
        $circleDenied33 = false;
        try {
            $this->consume($rows, $closed);
        } catch (RuntimeException $circleCaught33) {
            if ($circleCaught33 instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $circleCaught33;
            }
            $circleDenied33 = true;
            self::assertSame('Circle user projection byte budget exceeded', $circleCaught33->getMessage());
            self::assertSame(1, $closed);
        }
        self::assertTrue($circleDenied33, 'Aggregate byte bound reset at batch boundary');
    }
    public function testMalformedRowAndUnexpectedFieldsRefuseWithoutOmission(): void
    {
        foreach ([[], ['single_id' => 'x', 'user_id' => 'x', 'user_type' => 1, 'unexpected' => 'x'], ['single_id' => 'x', 'user_id' => '', 'user_type' => 1]] as $circleCase => $row) {
            $closed = 0;
            $circleDenied34 = false;
            try {
                $this->consume([$row], $closed);
            } catch (RuntimeException $circleCaught34) {
                if ($circleCaught34 instanceof \PHPUnit\Framework\AssertionFailedError) {
                    throw $circleCaught34;
                }
                $circleDenied34 = true;
            self::assertSame(['Circle user projection identity is malformed', 'Circle user projection identity is malformed', 'Circle user projection type is malformed'][$circleCase], $circleCaught34->getMessage());
                self::assertSame(1, $closed);
            }
            self::assertTrue($circleDenied34, 'Malformed selected row accepted');
        }
    }
}
