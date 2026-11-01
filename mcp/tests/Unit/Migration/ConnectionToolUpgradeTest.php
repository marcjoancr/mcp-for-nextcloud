<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Migration;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Migration\Version002100Date20261101010000;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class ConnectionToolUpgradeTest extends TestCase {
    public function testResumptionChecksTheBasicGrantPendingCodeAndShareVariants(): void {
        $sqlite = new SqliteDatabase($this, []);
        $db = $sqlite->dbal;
        $db->executeStatement('CREATE TABLE mcp_oauth_tokens (id INTEGER PRIMARY KEY, user_id TEXT)');
        $db->executeStatement('CREATE TABLE mcp_oauth_codes (id INTEGER PRIMARY KEY, user_id TEXT, tool_names TEXT, tool_variants TEXT)');
        $db->executeStatement('CREATE TABLE mcp_oauth_tools (user_id TEXT, connection_id INTEGER, tool_names TEXT, tool_variants TEXT, revision INTEGER, PRIMARY KEY(user_id, connection_id))');
        $db->insert('mcp_oauth_tokens', ['id' => 41, 'user_id' => 'alice']);
        $db->insert('mcp_oauth_codes', ['id' => 73, 'user_id' => 'alice']);
        $policy = $this->createStub(GrantPolicy::class);
        $policy->method('flaggedUsers')->willReturn(['alice']);
        $policy->method('within')->willReturnCallback(static fn ($uid, $work) => $work());
        $allow = static fn ($uid, $module, $op) => $module === 'files' && in_array($op, ['read','share'], true);
        $policy->method('granted')->willReturnCallback($allow);
        $policy->method('grantedAny')->willReturnCallback(static fn ($uid, $module, $ops) => $module === 'files' && array_intersect(['read','share'], $ops) !== []);
        $migration = new Version002100Date20261101010000($sqlite->connection(), $policy);
        $run = fn () => $migration->postSchemaChange($this->createStub(IOutput::class), static fn () => null, []);
        $run();
        $run();
        $rows = $db->fetchAllAssociative('SELECT tool_names, tool_variants FROM mcp_oauth_tools ORDER BY connection_id');
        self::assertCount(2, $rows);
        self::assertSame($rows[0], $rows[1]);
        self::assertSame($rows[0], $db->fetchAssociative('SELECT tool_names, tool_variants FROM mcp_oauth_codes WHERE id=73'));
        $names = json_decode($rows[0]['tool_names'], true, flags: JSON_THROW_ON_ERROR);
        self::assertContains('files_read', $names);
        self::assertNotContains('files_edit', $names);
        self::assertSame(['share'], json_decode($rows[0]['tool_variants'], true, flags: JSON_THROW_ON_ERROR)['files_share']);
        $db->update('mcp_oauth_tools', ['tool_variants' => '{}'], ['connection_id' => 41]);
        $this->expectException(\UnexpectedValueException::class);
        $run();
    }
}
