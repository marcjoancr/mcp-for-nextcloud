<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use InvalidArgumentException;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use UnexpectedValueException;

/** Per-tool Allow, Deny and explicit Neutral markers for Nextcloud users and groups. */
final class ToolGrantsMapper {
    public const TABLE = 'mcp_tool_grants';
    public const USER = 'user';
    public const GROUP = 'group';
    public const ALLOW = 'allow';
    public const DENY = 'deny';
    public const NEUTRAL = 'neutral';
    public const SERVICE = '_service';

    public function __construct(private IDBConnection $db) {}

    /** @param list<string> $principalIds @return array<string, array<string, string>> principal id => tool name => decision */
    public function forPrincipals(string $type, array $principalIds): array {
        self::assertType($type);
        $rows = [];
        foreach ($principalIds as $id) {
            self::assertPrincipal($id);
            $rows[$this->key($type, $id)] = ['id' => $id, 'tools' => []];
        }
        foreach (array_chunk($rows, 200, true) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $keys = array_map(fn (string $key) => $qb->createNamedParameter($key), array_keys($chunk));
            $result = $qb->select('principal_type', 'principal_key', 'principal_id', 'tool_name', 'decision')->from(self::TABLE)
                ->where($qb->expr()->in('principal_key', $keys))->executeQuery();
            try {
                while (($row = $result->fetch()) !== false) {
                    $key = $row['principal_key'] ?? null;
                    $id = $row['principal_id'] ?? null;
                    $name = $row['tool_name'] ?? null;
                    $decision = $row['decision'] ?? null;
                    if (($row['principal_type'] ?? null) !== $type || !is_string($key) || !isset($chunk[$key]) || $id !== $chunk[$key]['id']
                        || !is_string($name) || !self::validToolName($name)
                        || !is_string($decision) || !in_array($decision, [self::ALLOW, self::DENY, self::NEUTRAL], true)
                        || isset($rows[$key]['tools'][$name])) {
                        throw new UnexpectedValueException('Invalid stored tool grant');
                    }
                    $rows[$key]['tools'][$name] = $decision;
                }
            } finally {
                $result->closeCursor();
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $out[$row['id']] = $row['tools'];
        }
        return $out;
    }

    /** @return list<string> principals with a persisted decision for one tool, sorted bytewise */
    public function principalsForTool(string $type, string $toolName): array {
        self::assertType($type);
        if (!self::validToolName($toolName)) {
            throw new InvalidArgumentException('Invalid tool name');
        }
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('principal_key', 'principal_type', 'principal_id')->from(self::TABLE)
            ->where($qb->expr()->eq('principal_type', $qb->createNamedParameter($type)))
            ->andWhere($qb->expr()->eq('tool_name', $qb->createNamedParameter($toolName)))->executeQuery();
        $ids = [];
        try {
            while (($row = $result->fetch()) !== false) {
                if (($row['principal_type'] ?? null) !== $type || !is_string($row['principal_key'] ?? null)
                    || !is_string($row['principal_id'] ?? null)
                    || $row['principal_key'] !== $this->key($type, $row['principal_id'])) {
                    throw new UnexpectedValueException('Invalid stored tool grant principal');
                }
                $ids[$row['principal_key']] = $row['principal_id'];
            }
        } finally {
            $result->closeCursor();
        }
        $ids = array_values($ids);
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** Null deletes a row. Neutral is persisted because it suppresses a matching legacy rule. */
    public function set(string $type, string $principalId, string $toolName, ?string $decision): void {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Tool grants change only inside the policy writer transaction');
        }
        self::assertType($type);
        self::assertPrincipal($principalId);
        if (!self::validToolName($toolName) || ($decision !== null && !in_array($decision, [self::ALLOW, self::DENY, self::NEUTRAL], true))) {
            throw new InvalidArgumentException('Invalid tool grant');
        }
        $key = $this->key($type, $principalId);
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)->where($qb->expr()->eq('principal_key', $qb->createNamedParameter($key)))
            ->andWhere($qb->expr()->eq('tool_name', $qb->createNamedParameter($toolName)))->executeStatement();
        if ($decision === null) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->setValue('principal_type', $qb->createNamedParameter($type))
            ->setValue('principal_key', $qb->createNamedParameter($key))
            ->setValue('principal_id', $qb->createNamedParameter($principalId))
            ->setValue('tool_name', $qb->createNamedParameter($toolName))
            ->setValue('decision', $qb->createNamedParameter($decision))
            ->executeStatement();
    }

    /** Remove every tool decision for a principal during its confirmed lifecycle purge. */
    public function deletePrincipal(string $type, string $principalId): bool {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Tool grants are purged only inside the policy writer transaction');
        }
        self::assertType($type);
        self::assertPrincipal($principalId);
        $qb = $this->db->getQueryBuilder();
        return $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('principal_key', $qb->createNamedParameter($this->key($type, $principalId))))
            ->andWhere($qb->expr()->eq('principal_type', $qb->createNamedParameter($type)))
            ->executeStatement() > 0;
    }

    public static function validToolName(string $name): bool {
        return $name === self::SERVICE || (preg_match('/^[a-z][a-z0-9_]{0,95}$/', $name) === 1);
    }

    private function key(string $type, string $id): string {
        return hash('sha256', $type . "\0" . $id);
    }

    private static function assertType(string $type): void {
        if (!in_array($type, [self::USER, self::GROUP], true)) {
            throw new InvalidArgumentException('Invalid principal type');
        }
    }

    private static function assertPrincipal(string $id): void {
        if ($id === '' || str_contains($id, "\0") || !mb_check_encoding($id, 'UTF-8')) {
            throw new InvalidArgumentException('Invalid principal id');
        }
    }
}
