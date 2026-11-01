<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\OAuthClientsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\OAuth\Revocation\CredentialGeneration;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/** Per-OAuth-connection tool selections. Connection zero is the shared Basic/session profile. */
final class ConnectionToolsStore implements \OCA\Mcp\Modules\Files\Contracts\CheckoutSelection {
    public const TABLE = 'mcp_oauth_tools';
    public const BASIC_PROFILE = 0;

    public function __construct(
        private IDBConnection $db,
        private ITimeFactory $time,
        private TokenOwnerGate $ownerGate,
        private AuthUsersMapper $authUsers,
        private PolicyStateMapper $policyState,
        private OAuthClientsMapper $clients,
    ) {}

    /** @return array{tools:list<string>,variants:array<string,list<string>>,revision:string}|null null when missing or foreign */
    public function get(string $uid, int $connectionId): ?array {
        if ($uid === '' || $connectionId < 0) {
            return null;
        }
        if ($connectionId > 0 && !$this->liveAccessGrant($uid, $connectionId)) {
            return null;
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('tool_names', 'tool_variants', 'revision')->from(self::TABLE)
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('connection_id', $qb->createNamedParameter($connectionId, IQueryBuilder::PARAM_INT)))
                ->executeQuery();
            try {
                $row = $result->fetch();
            } finally {
                $result->closeCursor();
            }
            if (!is_array($row)) {
                return $connectionId === self::BASIC_PROFILE ? ['tools' => [], 'variants' => [], 'revision' => '0'] : null;
            }
            $tools = json_decode((string)$row['tool_names'], true);
            if (!is_array($tools) || !array_is_list($tools) || count($tools) > 1000) {
                throw new \UnexpectedValueException('Invalid stored connection tools');
            }
            foreach ($tools as $tool) {
                if (!is_string($tool) || $tool === '') {
                    throw new \UnexpectedValueException('Invalid stored connection tool');
                }
            }
            $variants = self::decodeVariants((string)($row['tool_variants'] ?? '{}'));
            if (array_diff(array_keys($variants), $tools) !== []) {
                throw new \UnexpectedValueException('Stored tool variants belong to an unselected tool');
            }
            return ['tools' => array_values(array_unique($tools)), 'variants' => $variants, 'revision' => (string)$row['revision']];
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot read connection tool selection', previous: $e);
        }
    }

    /** @param list<string> $tools @param array<string,list<string>> $variants
     * @return array{tools:list<string>,variants:array<string,list<string>>,revision:string}
     */
    public function save(string $uid, int $connectionId, array $tools, string $expectedRevision, array $variants = []): array {
        if ($uid === '' || $connectionId < 0 || count($tools) > 1000 || !self::validRevision($expectedRevision)) {
            throw new \InvalidArgumentException('Invalid connection tool selection');
        }
        if ($connectionId > 0 && !$this->liveAccessGrant($uid, $connectionId)) {
            throw new \OutOfBoundsException('Connection not found');
        }
        foreach ($tools as $tool) {
            if (!is_string($tool) || preg_match('/^[a-z][a-z0-9_]{0,127}$/D', $tool) !== 1) {
                throw new \InvalidArgumentException('Invalid connection tool selection');
            }
        }
        $tools = array_values(array_unique($tools));
        sort($tools, SORT_STRING);
        $current = $this->get($uid, $connectionId);
        if ($current === null) {
            throw new \OutOfBoundsException('Connection not found');
        }
        if ($current['revision'] !== $expectedRevision) {
            throw new ConnectionToolsConflict('Connection tools changed');
        }
        $variants = self::mergeVariants($tools, $variants, $current['variants'] ?? []);
        $newRevision = self::nextRevision($expectedRevision);
        try {
            if ($expectedRevision === '0') {
                $this->insert($uid, $connectionId, $tools, $newRevision, $variants);
            } else {
                $qb = $this->db->getQueryBuilder();
                $updated = $qb->update(self::TABLE)
                    ->set('tool_names', $qb->createNamedParameter(json_encode($tools, JSON_THROW_ON_ERROR)))
                    ->set('tool_variants', $qb->createNamedParameter(json_encode($variants, JSON_THROW_ON_ERROR)))
                    ->set('revision', $qb->createNamedParameter($newRevision, IQueryBuilder::PARAM_INT))
                    ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                    ->andWhere($qb->expr()->eq('connection_id', $qb->createNamedParameter($connectionId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter((int)$expectedRevision, IQueryBuilder::PARAM_INT)))
                    ->executeStatement();
                if ($updated !== 1) {
                    throw new ConnectionToolsConflict('Connection tools changed');
                }
            }
        } catch (ConnectionToolsConflict $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($expectedRevision !== '0') {
                throw new PolicyUnavailable('Cannot save connection tool selection', previous: $e);
            }
            $current = $this->get($uid, $connectionId);
            if ($current !== null) {
                throw new ConnectionToolsConflict('Connection tools changed', previous: $e);
            }
            throw new PolicyUnavailable('Cannot save connection tool selection', previous: $e);
        }
        return ['tools' => $tools, 'variants' => $variants, 'revision' => $newRevision];
    }

    /** Creates a token selection as part of the OAuth code exchange transaction. */
    public function seed(string $uid, int $connectionId, array $tools, array $variants = []): void {
        if ($uid === '' || $connectionId < 1 || !$this->ownsLiveGrant($uid, $connectionId)) {
            throw new \InvalidArgumentException('Invalid connection');
        }
        try {
            $tools = array_values(array_unique($tools));
            sort($tools, SORT_STRING);
            $this->insert($uid, $connectionId, $tools, '1', self::mergeVariants($tools, $variants, []));
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot create connection tool selection', previous: $e);
        }
    }

    /** The authorization check used by MCP and other authenticated entry points. */
    public function permits(string $uid, int $connectionId, string $toolName): bool {
        if ($connectionId > 0 && !$this->liveAccessGrant($uid, $connectionId)) {
            return false;
        }
        $selection = $this->get($uid, $connectionId);
        return $selection !== null && in_array($toolName, $selection['tools'], true);
    }

    /** Legacy checkout rows have no issuer id, so require every persisted profile to retain the tool. */
    public function permitsLegacyCheckout(string $uid, string $toolName): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('s.connection_id', 's.tool_names')
                ->from(self::TABLE, 's')
                ->leftJoin('s', 'mcp_oauth_tokens', 't', $qb->expr()->eq('t.id', 's.connection_id'))
                ->where($qb->expr()->eq('s.user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->eq('s.connection_id', $qb->createNamedParameter(self::BASIC_PROFILE, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->gte('t.refresh_expires', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT)),
                ))
                ->executeQuery();
            try {
                $found = false;
                while (($row = $result->fetch()) !== false) {
                    $found = true;
                    $tools = json_decode((string)$row['tool_names'], true);
                    if (!is_array($tools) || !array_is_list($tools) || !in_array($toolName, $tools, true)) {
                        return false;
                    }
                }
                return $found;
            } finally {
                $result->closeCursor();
            }
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot verify legacy checkout tool selection', previous: $e);
        }
    }

    /** Removes all profiles when Nextcloud deletes an account so a later UID reuse starts with no tools selected. */
    public function deleteUserSelections(string $uid): void {
        if ($uid === '') {
            return;
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::TABLE)
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->executeStatement();
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot remove deleted account tool selections', previous: $e);
        }
    }

    /** Whether an account has a Basic profile or any OAuth connection profile. */
    public function hasUserSelections(string $uid): bool {
        if ($uid === '') {
            return false;
        }
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('connection_id')->from(self::TABLE)
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->setMaxResults(1)->executeQuery();
            try {
                return $result->fetch() !== false;
            } finally {
                $result->closeCursor();
            }
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot check account tool selections', previous: $e);
        }
    }

    /** @param list<string> $tools */
    private function insert(string $uid, int $connectionId, array $tools, string $revision, array $variants = []): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->setValue('user_id', $qb->createNamedParameter($uid))
            ->setValue('connection_id', $qb->createNamedParameter($connectionId, IQueryBuilder::PARAM_INT))
            ->setValue('tool_names', $qb->createNamedParameter(json_encode($tools, JSON_THROW_ON_ERROR)))
            ->setValue('tool_variants', $qb->createNamedParameter(json_encode($variants, JSON_THROW_ON_ERROR)))
            ->setValue('revision', $qb->createNamedParameter((int)$revision, IQueryBuilder::PARAM_INT))
            ->executeStatement();
    }

    /** @param array<string,list<string>> $variants @return array<string,list<string>> */
    private static function decodeVariants(string $json): array {
        $variants = json_decode($json, true);
        if (!is_array($variants) || array_is_list($variants) && $variants !== []) {
            throw new \UnexpectedValueException('Invalid stored connection tool variants');
        }
        $result = [];
        foreach ($variants as $tool => $operations) {
            if (!is_string($tool) || preg_match('/^[a-z][a-z0-9_]{0,127}$/D', $tool) !== 1 || !is_array($operations) || !array_is_list($operations)) {
                throw new \UnexpectedValueException('Invalid stored connection tool variants');
            }
            foreach ($operations as $operation) {
                if (!is_string($operation) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $operation) !== 1) {
                    throw new \UnexpectedValueException('Invalid stored connection tool variants');
                }
            }
            $result[$tool] = array_values(array_unique($operations));
            sort($result[$tool], SORT_STRING);
        }
        return $result;
    }

    /** @param list<string> $tools @param array<string,list<string>> $submitted @param array<string,list<string>> $current
     * @return array<string,list<string>>
     */
    private static function mergeVariants(array $tools, array $submitted, array $current): array {
        $selected = array_fill_keys($tools, true);
        $result = [];
        foreach ($submitted as $tool => $operations) {
            if (!is_string($tool) || !isset($selected[$tool]) || !is_array($operations)) {
                throw new \InvalidArgumentException('Invalid connection tool variants');
            }
            foreach ($operations as $operation) {
                if (!is_string($operation) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $operation) !== 1) {
                    throw new \InvalidArgumentException('Invalid connection tool variants');
                }
            }
            $result[$tool] = array_values(array_unique($operations));
            sort($result[$tool], SORT_STRING);
        }
        foreach ($tools as $tool) {
            if (!array_key_exists($tool, $result) && isset($current[$tool])) {
                $result[$tool] = $current[$tool];
            }
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** A selection cannot authorize a deleted, expired, or foreign OAuth grant. */
    private function ownsLiveGrant(string $uid, int $connectionId): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id')->from('mcp_oauth_tokens')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($connectionId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->gte('refresh_expires', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)->executeQuery();
            try {
                return $result->fetch() !== false;
            } finally {
                $result->closeCursor();
            }
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot verify OAuth connection ownership', previous: $e);
        }
    }

    /** The checkout capability rechecks the OAuth lifetime, revocation epoch, user and managed-client state. */
    private function liveAccessGrant(string $uid, int $connectionId): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('client_id', 'auth_global_epoch', 'auth_gen')
                ->from('mcp_oauth_tokens')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($connectionId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->gte('refresh_expires', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT)))
                ->setMaxResults(1)->executeQuery();
            try {
                $row = $result->fetch();
            } finally {
                $result->closeCursor();
            }
            if (!is_array($row)) {
                return false;
            }
            $generations = $this->authUsers->generations($uid);
            if (!CredentialGeneration::live($row, $generations, $this->policyState->state()['auth_epoch'])) {
                return false;
            }
            if (ClientResolver::builtInDisabled($this->clients, (string)$row['client_id'])) {
                return false;
            }
            return $this->ownerGate->owner($uid, $generations['issue_seq']) !== null;
        } catch (PolicyUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot validate OAuth connection for checkout', previous: $e);
        }
    }

    private static function validRevision(string $revision): bool {
        return preg_match('/^(0|[1-9][0-9]{0,17})$/D', $revision) === 1;
    }

    private static function nextRevision(string $revision): string {
        if (!self::validRevision($revision) || strlen($revision) > 17 || (strlen($revision) === 17 && $revision >= '9223372036854775807')) {
            throw new \InvalidArgumentException('Invalid connection tool revision');
        }
        return (string)((int)$revision + 1);
    }
}
