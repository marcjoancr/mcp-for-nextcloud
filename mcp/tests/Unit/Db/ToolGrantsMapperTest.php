<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Db;

use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Migration\Version002100Date20261101000000;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class ToolGrantsMapperTest extends TestCase {
    public function testServiceGroupIdsRemainStringsWhenEnumeratingNumericGroups(): void {
        $sqlite = new SqliteDatabase($this, [new Version002100Date20261101000000()]);
        $db = $sqlite->connection();
        $grants = new ToolGrantsMapper($db);
        $db->beginTransaction();
        foreach (['123', '00123', '0', 'staff'] as $gid) {
            $grants->set(ToolGrantsMapper::GROUP, $gid, ToolGrantsMapper::SERVICE, ToolGrantsMapper::ALLOW);
        }
        $db->commit();

        self::assertSame(['0', '00123', '123', 'staff'], $grants->principalsForTool(ToolGrantsMapper::GROUP, ToolGrantsMapper::SERVICE));
    }

    public function testSaveAndClearAreDistinctFromNeutralMarker(): void {
        self::assertSame('neutral', ToolGrantsMapper::NEUTRAL);
        self::assertNotSame(ToolGrantsMapper::NEUTRAL, ToolGrantsMapper::ALLOW);
        self::assertNotSame(ToolGrantsMapper::NEUTRAL, ToolGrantsMapper::DENY);
    }
}
