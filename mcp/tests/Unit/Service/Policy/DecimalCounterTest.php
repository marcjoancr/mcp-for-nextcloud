<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Service\Policy;

use OCA\Mcp\Service\Policy\DecimalCounter;
use PHPUnit\Framework\TestCase;

final class DecimalCounterTest extends TestCase {
    public function testCanonicalCountersPreserveBytesAbove32BitAndJavascriptPrecision(): void {
        foreach (['0', '2147483648', '9007199254740993', DecimalCounter::MAX_EXPECTED] as $value) {
            $this->assertSame($value, DecimalCounter::validate($value));
        }
    }

    public function testRejectsNonCanonicalValuesAndOverflowWithoutNumericConversion(): void {
        foreach (['', '00', '01', '-1', '+1', ' 1', '1 ', '1.0', '1e3', '١', DecimalCounter::MAX, '9223372036854775808', str_repeat('9', 100)] as $value) {
            try {
                DecimalCounter::validate($value);
                $this->fail('Invalid counter accepted: ' . $value);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(DecimalCounter::MAX, DecimalCounter::validate(DecimalCounter::MAX, DecimalCounter::MAX));
    }

    public function testCompareOrdersByMagnitudeAboveEveryPlatformLimitAndRejectsNonCanonicalValues(): void {
        $this->assertSame(0, DecimalCounter::compare('0', '0'));
        $this->assertSame(-1, DecimalCounter::compare('9', '10'));
        $this->assertSame(1, DecimalCounter::compare('10', '9'));
        $this->assertSame(1, DecimalCounter::compare('9007199254740993', '9007199254740992'));
        $this->assertSame(-1, DecimalCounter::compare(DecimalCounter::MAX_EXPECTED, DecimalCounter::MAX));
        foreach ([['01', '1'], ['1', '-1'], ['1', '9223372036854775808']] as [$a, $b]) {
            try {
                DecimalCounter::compare($a, $b);
                $this->fail("Compared $a with $b");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
