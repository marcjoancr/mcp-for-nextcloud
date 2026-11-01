<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Db;

use OCA\Mcp\Db\ToolGrantsMapper;
use PHPUnit\Framework\TestCase;

final class ToolGrantsMapperTest extends TestCase {
    public function testSaveAndClearAreDistinctFromNeutralMarker(): void {
        self::assertSame('neutral', ToolGrantsMapper::NEUTRAL);
        self::assertNotSame(ToolGrantsMapper::NEUTRAL, ToolGrantsMapper::ALLOW);
        self::assertNotSame(ToolGrantsMapper::NEUTRAL, ToolGrantsMapper::DENY);
    }
}
