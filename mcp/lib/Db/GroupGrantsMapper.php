<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantPolicy;
use OCP\IDBConnection;
use UnexpectedValueException;

/**
 * Group-grant storage, deliberately not a QBMapper: its inherited write methods must not be exposed. A row means
 * Allow; no row means no grant. This store does not resolve user access or group membership. Its only write, the
 * purge of a group's rows, runs inside the policy writer's transaction (group lifecycle, contracts §3.3.5).
 */
final class GroupGrantsMapper {
    /** Leaves room below the parameter limits of the supported database engines. */
    private const CHUNK_SIZE = 200;

    public function __construct(private IDBConnection $db) {}

    /**
     * Returns each distinct requested id in input order, including groups without grants. Identifiers stay strings,
     * so numeric-looking ids cannot become PHP array keys. Module and operation lists are sorted bytewise.
     *
     * @param list<string> $groupIds opaque, non-empty UTF-8 group ids, with no NUL bytes
     * @return list<array{groupId:string, grants:array<string, list<string>>}>
     * @throws InvalidArgumentException for an invalid input list or id
     * @throws UnexpectedValueException for a digest collision, mismatched identity or invalid stored grant;
     *     the whole read fails instead of returning a partially trusted result
     */
    public function forGroups(array $groupIds): array {
        if (!array_is_list($groupIds)) {
            throw new InvalidArgumentException('Group ids must be a list');
        }
        $groups = [];
        foreach ($groupIds as $gid) {
            if (!is_string($gid) || $gid === '' || str_contains($gid, "\0") || !mb_check_encoding($gid, 'UTF-8')) {
                throw new InvalidArgumentException('Invalid group id');
            }
            $key = hash('sha256', "group\0" . $gid);
            if (isset($groups[$key]) && $groups[$key]['groupId'] !== $gid) {
                throw new UnexpectedValueException('Group identity collision');
            }
            $groups[$key] = ['groupId' => $gid, 'grants' => []];
        }
        foreach (array_chunk($groups, self::CHUNK_SIZE, true) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $keys = array_map(fn (string $key) => $qb->createNamedParameter($key), array_keys($chunk));
            $result = $qb->select('group_key', 'group_id', 'module', 'operation')->from('mcp_group_grants')
                ->where($qb->expr()->in('group_key', $keys))->executeQuery();
            try {
                while (($row = $result->fetch()) !== false) {
                    $key = $row['group_key'] ?? null;
                    $gid = $row['group_id'] ?? null;
                    $module = $row['module'] ?? null;
                    $operation = $row['operation'] ?? null;
                    if (!is_string($key) || !isset($chunk[$key]) || $gid !== $chunk[$key]['groupId']
                        || !is_string($module) || !is_string($operation)
                        || !(GrantPolicy::inCatalog($module, $operation) || ($module === '_system' && $operation === 'eligible'))
                        || in_array($operation, $groups[$key]['grants'][$module] ?? [], true)) {
                        throw new UnexpectedValueException('Invalid stored group grant');
                    }
                    $groups[$key]['grants'][$module][] = $operation;
                }
            } finally {
                $result->closeCursor();
            }
        }
        foreach ($groups as &$group) {
            ksort($group['grants'], SORT_STRING);
            foreach ($group['grants'] as &$operations) {
                sort($operations, SORT_STRING);
            }
            unset($operations);
        }
        unset($group);
        return array_values($groups);
    }

    /**
     * Removes every grant row of one group, as the purge of its lifecycle does. The rows are read first through
     * forGroups(), which compares each stored id byte for byte, so a digest collision fails closed before the delete.
     *
     * @param string $gid Nextcloud group id
     * @return int rows removed
     * @throws \LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid id
     * @throws UnexpectedValueException for a digest collision or an invalid stored grant; nothing is deleted
     * @throws \OCP\DB\Exception when a statement fails
     */
    public function deleteGroup(string $gid): int {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Group grants change only inside the policy writer transaction');
        }
        $this->forGroups([$gid]);
        $qb = $this->db->getQueryBuilder();
        return $qb->delete('mcp_group_grants')
            ->where($qb->expr()->eq('group_key', $qb->createNamedParameter(hash('sha256', "group\0" . $gid))))
            ->executeStatement();
    }
}
