<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use InvalidArgumentException;
use LogicException;
use OCA\Mcp\Service\Policy\DecimalCounter;
use OCP\IDBConnection;
use UnexpectedValueException;

/**
 * Identity lifecycle of a GID trusted with policy (contracts §3.3.5): only an active row lets the group's grants
 * count. gen changes on every transition, so a delayed cleanup conditioned on the gen it read never removes a policy
 * saved later; policy_rev is the revision of the commit that saved the group's policy. mcp_group_grants is untouched.
 *
 * Composition mapper: writes run only inside the policy writer's transaction, under its global mutex.
 */
class GroupLifeMapper {
    public const TABLE = 'mcp_group_life';
    public const ACTIVE = 'active';
    public const PENDING_DELETE = 'pending_delete';
    public const NEEDS_CONFIRM = 'needs_confirm';
    public const STATES = [self::ACTIVE, self::PENDING_DELETE, self::NEEDS_CONFIRM];
    /** Leaves room below the parameter limits of the supported database engines. */
    private const CHUNK_SIZE = 200;

    public function __construct(private IDBConnection $db) {}

    /**
     * @param string $gid Nextcloud group id
     * @return array{groupId:string, state:string, gen:string, policyRev:string}|null null when the group has no lifecycle
     * @throws InvalidArgumentException for an invalid group id
     * @throws UnexpectedValueException for a digest collision or an invalid stored row
     * @throws \OCP\DB\Exception when the query fails
     */
    public function get(string $gid): ?array {
        return $this->forGroups([$gid])[0] ?? null;
    }

    /**
     * Lifecycles of the requested groups that have one, in input order without duplicates.
     *
     * @param list<string> $groupIds Nextcloud group ids
     * @return list<array{groupId:string, state:string, gen:string, policyRev:string}>
     * @throws InvalidArgumentException for an invalid list or id
     * @throws UnexpectedValueException for a digest collision or an invalid stored row; the whole read fails
     * @throws \OCP\DB\Exception when the query fails
     */
    public function forGroups(array $groupIds): array {
        if (!array_is_list($groupIds)) {
            throw new InvalidArgumentException('Group ids must be a list');
        }
        $wanted = [];
        foreach ($groupIds as $gid) {
            if (!is_string($gid)) {
                throw new InvalidArgumentException('Invalid group id');
            }
            $wanted[IdentityKey::group($gid)] = $gid;
        }
        $found = [];
        foreach (array_chunk($wanted, self::CHUNK_SIZE, true) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $keys = array_map(fn (string $key) => $qb->createNamedParameter($key), array_keys($chunk));
            $result = $qb->select('group_key', 'group_id', 'state', 'gen', 'policy_rev')->from(self::TABLE)
                ->where($qb->expr()->in('group_key', $keys))->executeQuery();
            try {
                while (($row = $result->fetch()) !== false) {
                    $key = $row['group_key'] ?? null;
                    if (!is_string($key) || !isset($chunk[$key]) || ($row['group_id'] ?? null) !== $chunk[$key]
                        || !in_array($row['state'] ?? null, self::STATES, true) || !self::validGen($row['gen'] ?? null)) {
                        throw new UnexpectedValueException('Invalid stored group lifecycle');
                    }
                    try {
                        $rev = DecimalCounter::validate((string)$row['policy_rev'], DecimalCounter::MAX);
                    } catch (InvalidArgumentException $e) {
                        throw new UnexpectedValueException('Invalid stored group lifecycle', 0, $e);
                    }
                    $found[$key] = ['groupId' => $chunk[$key], 'state' => $row['state'], 'gen' => $row['gen'], 'policyRev' => $rev];
                }
            } finally {
                $result->closeCursor();
            }
        }
        return array_values(array_intersect_key(array_replace($wanted, $found), $found));
    }

    /**
     * Creates or replaces the lifecycle of a group, as a save of its policy does.
     *
     * @param string $gid Nextcloud group id
     * @param string $state one of STATES
     * @param string $gen 32 lowercase hex characters, new for this transition
     * @param string $policyRev canonical revision of the commit that saves the group's policy
     * @throws LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid id, state, gen or revision
     * @throws UnexpectedValueException for a digest collision
     * @throws \OCP\DB\Exception when a statement fails
     */
    public function put(string $gid, string $state, string $gen, string $policyRev): void {
        $this->requireTransaction();
        self::validate($state, $gen);
        DecimalCounter::validate($policyRev, DecimalCounter::MAX);
        $qb = $this->db->getQueryBuilder();
        if ($this->get($gid) === null) {
            $qb->insert(self::TABLE)
                ->setValue('group_key', $qb->createNamedParameter(IdentityKey::group($gid)))
                ->setValue('group_id', $qb->createNamedParameter($gid))
                ->setValue('state', $qb->createNamedParameter($state))
                ->setValue('gen', $qb->createNamedParameter($gen))
                ->setValue('policy_rev', $qb->createNamedParameter($policyRev))
                ->executeStatement();
            return;
        }
        $qb->update(self::TABLE)
            ->set('state', $qb->createNamedParameter($state))
            ->set('gen', $qb->createNamedParameter($gen))
            ->set('policy_rev', $qb->createNamedParameter($policyRev))
            ->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($gid))))
            ->executeStatement();
    }

    /**
     * Moves the lifecycle to $state with a new gen, only if it still carries $expectedGen.
     *
     * @param string $gid Nextcloud group id
     * @param string $expectedGen gen read before the transition
     * @param string $state one of STATES
     * @param string $gen new gen, different from $expectedGen
     * @return bool whether this call moved it
     * @throws LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid id, state or gen
     * @throws UnexpectedValueException for a digest collision
     * @throws \OCP\DB\Exception when a statement fails
     */
    public function transition(string $gid, string $expectedGen, string $state, string $gen): bool {
        $this->requireTransaction();
        self::validate($state, $gen);
        if (!self::validGen($expectedGen) || $expectedGen === $gen) {
            throw new InvalidArgumentException('A transition needs a new gen');
        }
        if (($this->get($gid)['gen'] ?? null) !== $expectedGen) {
            return false;
        }
        $qb = $this->db->getQueryBuilder();
        return $qb->update(self::TABLE)
            ->set('state', $qb->createNamedParameter($state))
            ->set('gen', $qb->createNamedParameter($gen))
            ->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($gid))))
            ->andWhere($qb->expr()->eq('gen', $qb->createNamedParameter($expectedGen)))
            ->executeStatement() === 1;
    }

    /**
     * Removes the lifecycle only if it still carries $expectedGen. The caller removes the group's grant rows in the
     * same transaction.
     *
     * @param string $gid Nextcloud group id
     * @param string $expectedGen gen read before the removal
     * @return bool whether this call removed it
     * @throws LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid id or gen
     * @throws UnexpectedValueException for a digest collision
     * @throws \OCP\DB\Exception when the statement fails
     */
    public function delete(string $gid, string $expectedGen): bool {
        $this->requireTransaction();
        if (!self::validGen($expectedGen)) {
            throw new InvalidArgumentException('Invalid gen');
        }
        if (($this->get($gid)['gen'] ?? null) !== $expectedGen) {
            return false;
        }
        $qb = $this->db->getQueryBuilder();
        return $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($gid))))
            ->andWhere($qb->expr()->eq('gen', $qb->createNamedParameter($expectedGen)))
            ->executeStatement() === 1;
    }

    private static function validate(string $state, string $gen): void {
        if (!in_array($state, self::STATES, true) || !self::validGen($gen)) {
            throw new InvalidArgumentException('Invalid group lifecycle');
        }
    }

    private static function validGen(mixed $gen): bool {
        return is_string($gen) && preg_match('/^[0-9a-f]{32}$/D', $gen) === 1;
    }

    private function requireTransaction(): void {
        if (!$this->db->inTransaction()) {
            throw new LogicException('Group lifecycles change only inside the policy writer transaction');
        }
    }
}
