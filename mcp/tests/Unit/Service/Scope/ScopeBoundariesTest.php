<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Service\Scope;

use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Service\GrantMatrix;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\LogsAccess;
use OCA\Mcp\Service\Policy\PolicyEventPayload;
use OCA\Mcp\Service\Policy\PolicyUnavailable;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The edges of the scope administration: the matrix shows only {mode, count}, a scope read is one statement, the
 * receipt form is canonical, and no tool reaches the native enumerator.
 */
final class ScopeBoundariesTest extends TestCase {
    private ScopeFixture $fx;

    protected function setUp(): void {
        $this->fx = new ScopeFixture($this);
    }

    private function matrix(?ResourceScopeMapper $scopes): GrantMatrix {
        $policy = $this->createMock(GrantPolicy::class);
        $policy->method('forUsers')->willReturnCallback(static fn (array $uids): array => array_fill_keys($uids, []));
        return new GrantMatrix($policy, $this->createMock(IUserManager::class), $this->createMock(IGroupManager::class),
            $this->createMock(IAppManager::class), $this->createMock(LogsAccess::class), $scopes);
    }

    public function testTheMatrixShowsModeAndCountWithoutIdsOrNames(): void {
        $writer = $this->fx->writer();
        $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->projects, $this->fx->archive]);
        $writer->setResourceScope($this->fx->revision(), 'root', 'alice', 'tasks', []);

        $rows = $this->matrix($this->fx->scopes)->rows([$this->fx->user('alice'), $this->fx->user('bob')]);

        $this->assertSame([
            'files' => ['mode' => 'restricted', 'count' => 2],
            'calendar' => ['mode' => 'unrestricted', 'count' => null],
            'tasks' => ['mode' => 'restricted', 'count' => 0],
        ], $rows[0]['scope']);
        $this->assertSame(['unrestricted'], array_values(array_unique(array_column($rows[1]['scope'], 'mode'))));
        $this->assertStringNotContainsString($this->fx->projects, (string)json_encode($rows));
    }

    public function testTheMatrixFailsInsteadOfShowingUnrestrictedWhenScopesCannotBeRead(): void {
        $this->fx->database->dbal->insert(ResourceScopeMapper::MODES_TABLE, ['user_key' => \OCA\Mcp\Db\IdentityKey::user('alice'), 'module' => 'files', 'user_id' => 'mallory', 'policy_rev' => '1']);
        $this->expectException(PolicyUnavailable::class);
        $this->matrix($this->fx->scopes)->rows([$this->fx->user('alice')]);
    }

    /** Review F1 of B1: a mode and its items come from one statement, never from two reads of different commits. */
    public function testAScopeReadIsOneStatement(): void {
        $this->fx->writer()->setResourceScope($this->fx->revision(), 'root', 'alice', 'files', [$this->fx->projects]);
        $statements = 0;
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(function () use (&$statements) {
            ++$statements;
            return $this->fx->db->getQueryBuilder();
        });
        $scopes = new ResourceScopeMapper($db);

        $this->assertSame([$this->fx->projects], $scopes->forModule('alice', 'files')['items']);
        $this->assertSame(1, $statements);
        $this->assertSame(['files'], array_keys($scopes->forUser('alice')));
        $this->assertSame(2, $statements);
        $this->assertSame(['alice' => ['files' => 1], 'bob' => []], $scopes->counts(['alice', 'bob']));
        $this->assertSame(3, $statements);
    }

    /** @return array<string, array{?string, bool}> raw scope values of a receipt and whether they are canonical */
    public static function scopeValues(): array {
        return [
            'unrestricted' => [null, true], 'restricted to nothing' => ['[]', true], 'sorted' => ['["2","10"]', true],
            'unsorted' => ['["10","2"]', false], 'duplicate' => ['["2","2"]', false], 'integer' => ['[2]', false],
            'leading zero' => ['["02"]', false], 'zero' => ['["0"]', false], 'object' => ['{"a":"1"}', false],
            'spaced' => ['[ "2" ]', false], 'not json' => ['2', false],
        ];
    }

    #[DataProvider('scopeValues')]
    public function testOnlyTheCanonicalScopeFormIsAReceiptValue(?string $value, bool $valid): void {
        $this->assertSame($valid, PolicyEventPayload::scopeItems($value) !== false);
        $change = ['uid' => 'alice', 'key' => 'scope_files', 'before' => $value === '["5"]' ? null : '["5"]', 'after' => $value];
        try {
            PolicyEventPayload::receipt('root', PolicyEventPayload::ACTION_SCOPE, '1', '2', [$change]);
            $this->assertTrue($valid);
        } catch (\UnexpectedValueException) {
            $this->assertFalse($valid);
        }
    }

    public function testScopeKeysAreNoGrantCellsAndGrantCommandsCannotWriteThem(): void {
        $this->assertSame('files', PolicyEventPayload::scopeModule('scope_files'));
        $this->assertNull(PolicyEventPayload::scopeModule('scope_notes'));
        $this->assertFalse(PolicyEventPayload::validUserKey('scope_files'));
        $this->expectException(\InvalidArgumentException::class);
        $this->fx->writer()->applyCells($this->fx->revision(), 'root', PolicyEventPayload::ACTION_COMMANDS, [['uid' => 'alice', 'key' => 'scope_files', 'value' => null]]);
    }

    /** The enumerator lists what the account reaches in Nextcloud; a tool must never use it as its view. */
    public function testNoToolOrMcpEndpointUsesTheNativeEnumerator(): void {
        $root = dirname(__DIR__, 4) . '/lib';
        $users = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_contains((string)file_get_contents((string)$file), 'NativeResourceEnumerator')) {
                $users[] = substr((string)$file, strlen($root) + 1);
            }
        }
        sort($users);
        $this->assertSame([
            'Controller/MyScopeController.php',
            'Controller/ScopeController.php',
            'Service/Policy/PolicyWriter.php',
            'Service/Scope/NativeResourceEnumerator.php',
        ], $users);
    }
}
