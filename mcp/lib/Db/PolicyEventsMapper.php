<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use InvalidArgumentException;
use LogicException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use UnexpectedValueException;

/**
 * The app's policy events (contracts §1, §3.2): receipts, audit and cleanup, each with its own kind. Events are
 * written by the policy writer in the transaction of the change they describe, so they exist exactly when the change
 * committed; delivery happens after the commit and may repeat, which the event id lets a reader correlate.
 */
class PolicyEventsMapper {
    public const TABLE = 'mcp_policy_events';
    public const KIND_RECEIPT = 'receipt';
    public const KIND_AUDIT = 'audit';
    public const KIND_CLEANUP = 'cleanup';
    public const KINDS = [self::KIND_RECEIPT, self::KIND_AUDIT, self::KIND_CLEANUP];
    public const STATUS_PENDING = 'pending';

    public function __construct(private IDBConnection $db, private ITimeFactory $time) {}

    /**
     * @param string $id event id: 32 lowercase hex characters, optionally followed by '-' and a sequence number
     * @param string $kind one of KINDS
     * @param array<array-key, mixed> $payload JSON-encodable data, validated per kind by its producer
     * @throws LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid id or kind, or a payload that cannot be encoded
     * @throws \OCP\DB\Exception when the insert fails, including a duplicate id
     */
    public function append(string $id, string $kind, array $payload): void {
        if (!$this->db->inTransaction()) {
            throw new LogicException('Policy events are written only inside the policy writer transaction');
        }
        if (!self::validId($id) || !in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Invalid policy event');
        }
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Invalid policy event payload', 0, $e);
        }
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->setValue('id', $qb->createNamedParameter($id))
            ->setValue('kind', $qb->createNamedParameter($kind))
            ->setValue('status', $qb->createNamedParameter(self::STATUS_PENDING))
            ->setValue('payload', $qb->createNamedParameter($json))
            ->setValue('created_at', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT))
            ->setValue('attempts', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->executeStatement();
    }

    /**
     * @param string $id event id
     * @return array{id:string, kind:string, status:string, payload:array, createdAt:int, attempts:int}|null
     * @throws UnexpectedValueException for an invalid stored event
     * @throws \OCP\DB\Exception when the query fails
     */
    public function find(string $id): ?array {
        if (!self::validId($id)) {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id', 'kind', 'status', 'payload', 'created_at', 'attempts')->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))->executeQuery();
        try {
            $row = $result->fetch();
        } finally {
            $result->closeCursor();
        }
        return $row === false ? null : self::event($row);
    }

    /**
     * Oldest pending events first, through the (status, created_at) index.
     *
     * @param int $limit at most this many events, 1..500
     * @return list<array{id:string, kind:string, status:string, payload:array, createdAt:int, attempts:int}>
     * @throws InvalidArgumentException for a limit out of range
     * @throws UnexpectedValueException for an invalid stored event
     * @throws \OCP\DB\Exception when the query fails
     */
    public function pending(int $limit): array {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Invalid limit');
        }
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id', 'kind', 'status', 'payload', 'created_at', 'attempts')->from(self::TABLE)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING)))
            ->orderBy('created_at')->addOrderBy('id')->setMaxResults($limit)->executeQuery();
        $events = [];
        try {
            while (($row = $result->fetch()) !== false) {
                $events[] = self::event($row);
            }
        } finally {
            $result->closeCursor();
        }
        return $events;
    }

    /** @param array<string, mixed> $row */
    private static function event(array $row): array {
        $id = $row['id'] ?? null;
        $kind = $row['kind'] ?? null;
        $status = $row['status'] ?? null;
        try {
            $payload = json_decode((string)($row['payload'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UnexpectedValueException('Invalid stored policy event', 0, $e);
        }
        if (!is_string($id) || !self::validId($id) || !in_array($kind, self::KINDS, true) || !is_string($status)
            || !is_array($payload) || !is_numeric($row['created_at'] ?? null) || !is_numeric($row['attempts'] ?? null)) {
            throw new UnexpectedValueException('Invalid stored policy event');
        }
        return ['id' => $id, 'kind' => $kind, 'status' => $status, 'payload' => $payload,
            'createdAt' => (int)$row['created_at'], 'attempts' => (int)$row['attempts']];
    }

    private static function validId(string $id): bool {
        return preg_match('/^[0-9a-f]{32}(-[1-9][0-9]{0,8})?$/D', $id) === 1;
    }
}
