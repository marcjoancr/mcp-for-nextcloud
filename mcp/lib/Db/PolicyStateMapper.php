<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use OCA\Mcp\Service\Policy\DecimalCounter;
use OCA\Mcp\Service\Policy\PolicyConflict;
use OCA\Mcp\Service\Policy\PolicyUnavailable;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** The server singleton connection is shared with official config and OAuth storage. */
class PolicyStateMapper {
    public const TABLE = 'mcp_policy_state';

    public function __construct(private IDBConnection $db) {}

    /** @return array{revision:string, lock_seq:string, auth_epoch:string} */
    public function state(): array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('revision', 'lock_seq', 'auth_epoch')->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        try {
            $row = $result->fetch();
        } finally {
            $result->closeCursor();
        }
        if ($row === false) {
            throw new PolicyUnavailable('Missing policy state');
        }
        $state = [];
        foreach (['revision', 'lock_seq', 'auth_epoch'] as $key) {
            try {
                $state[$key] = DecimalCounter::validate((string)$row[$key], DecimalCounter::MAX);
            } catch (\InvalidArgumentException $e) {
                throw new PolicyUnavailable('Corrupt policy counter', previous: $e);
            }
        }
        return $state;
    }

    /** Acquires a write lock before any local read, including on SQLite. */
    public function acquire(): void {
        $qb = $this->db->getQueryBuilder();
        $count = $qb->update(self::TABLE)->set('lock_seq', $qb->createFunction('lock_seq + 1'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('lock_seq', $qb->createNamedParameter(DecimalCounter::MAX)))
            ->executeStatement();
        if ($count !== 1) {
            throw new PolicyUnavailable('Missing or exhausted policy mutex');
        }
    }

    public function compareAndSet(string $expected): void {
        DecimalCounter::validate($expected);
        $qb = $this->db->getQueryBuilder();
        $count = $qb->update(self::TABLE)->set('revision', $qb->createFunction('revision + 1'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expected)))
            ->executeStatement();
        if ($count !== 1) {
            throw new PolicyConflict();
        }
    }
}
