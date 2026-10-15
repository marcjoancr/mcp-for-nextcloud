<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service\Policy;

use Closure;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Service\GrantPolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\DB\Exception as DatabaseException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use Throwable;

/**
 * Owns the top-level transaction. Trusted app commands prepare remote observations outside the mutex and
 * perform only local statements inside it. All participants use the server's singleton IDBConnection. Events the
 * command emits through its PolicyOutbox are persisted in the same transaction, after the revision CAS (the lock
 * order of contracts §2.1: mutex, app state, official config, then epochs/outbox/recheck).
 */
class PolicyWriter {
    /** Seconds an administrator may undo a command through its receipt (contracts §2.3). */
    public const RECEIPT_TTL = 600;
    /** Most users one administrative command may touch. */
    public const MAX_UIDS = 50;
    /** Most distinct cells one administrative command may write. */
    public const MAX_CELLS = 2500;

    private bool $usable = true;

    public function __construct(
        private IDBConnection $db,
        private PolicyStateMapper $state,
        private PolicyEventsMapper $events,
        private IConfig $config,
        private IUserConfig $userConfig,
        private IAppConfig $appConfig,
        private IUserManager $users,
        private OAuthStore $oauth,
        private ITimeFactory $time,
    ) {}

    /**
     * Administrative admission; the HTTP adapter supplies the required canonical revision.
     * The local callback returns whether raw policy changed; it may throw SnapshotInvalidated when an epoch,
     * cause set or single-use row differs from its external observation. A logical command id survives retries.
     * @param list<string> $uids every user whose official config cache may be touched
     * @param Closure(array,string):mixed $prepare remote work, outside any transaction
     * @param Closure(mixed,array,string,PolicyOutbox):bool $apply local work only, under the global mutex
     * @return array{revision:string, changed:bool, commandId:string}
     * @throws PolicyConflict when the expected revision is stale, before or after the retries
     * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
     */
    public function runAdmin(string $expectedRevision, array $uids, Closure $prepare, Closure $apply): array {
        DecimalCounter::validate($expectedRevision);
        if (count(array_unique($uids)) > self::MAX_UIDS) {
            throw new \InvalidArgumentException('Too many policy users');
        }
        return $this->run($uids, $prepare, $apply, $expectedRevision);
    }

    /**
     * Internal commands (including processed directory events) still validate their observed revision and use CAS.
     * Directory observations alone return false and never advance revision.
     * @param list<string> $uids
     * @param Closure(array,string):mixed $prepare
     * @param Closure(mixed,array,string,PolicyOutbox):bool $apply
     * @return array{revision:string, changed:bool, commandId:string}
     * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
     */
    public function runInternal(array $uids, Closure $prepare, Closure $apply): array {
        return $this->run($uids, $prepare, $apply, null);
    }

    /**
     * Turns the service switch on or off; a repeated disable still revokes every connection.
     *
     * @param bool $enabled new state of the global service switch
     * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
     */
    public function setGlobalEnabled(bool $enabled): void {
        $this->change(null, GrantPolicy::SERVICE_KEY, $enabled ? '1' : '0');
    }

    /**
     * @param string $uid Nextcloud user id
     * @param bool $enabled new eligibility; false also revokes the user's connections
     * @throws \InvalidArgumentException for a user the server does not know
     * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
     */
    public function setEligible(string $uid, bool $enabled): void {
        $this->change($uid, GrantPolicy::ELIGIBLE_KEY, $enabled ? '1' : '0');
    }

    /**
     * @param string $uid Nextcloud user id
     * @param bool $enabled new personal connection state
     * @throws \InvalidArgumentException for a user the server does not know
     * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
     */
    public function setConnected(string $uid, bool $enabled): void {
        $this->change($uid, GrantPolicy::CONNECTED_KEY, $enabled ? '1' : '0');
    }

    /**
     * Stores an explicit individual allow or deny, which differs from an absent override.
     *
     * @param string $uid Nextcloud user id
     * @param string $module catalog module
     * @param string $operation catalog operation of that module
     * @param bool $enabled explicit allow (true) or deny (false)
     * @throws \InvalidArgumentException for an unknown user, module or operation
     * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
     */
    public function setGrant(string $uid, string $module, string $operation, bool $enabled): void {
        if (!GrantPolicy::inCatalog($module, $operation)) {
            throw new \InvalidArgumentException('Unknown module or operation');
        }
        $this->change($uid, 'grant_' . $module . '_' . $operation, $enabled ? '1' : '0');
    }

    /**
     * Removes an individual override through the same transaction; absence remains absence.
     *
     * @param string $uid Nextcloud user id
     * @param string $module catalog module
     * @param string $operation catalog operation of that module
     * @throws \InvalidArgumentException for an unknown user, module or operation
     * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
     */
    public function resetOverride(string $uid, string $module, string $operation): void {
        if (!GrantPolicy::inCatalog($module, $operation)) {
            throw new \InvalidArgumentException('Unknown module or operation');
        }
        $this->change($uid, 'grant_' . $module . '_' . $operation, null);
    }

    /**
     * One administrative command over many user cells, as one transaction: every cell is written or none is. Cells
     * whose raw value is already the requested one are no-ops; a command where every cell is a no-op commits nothing
     * and leaves the revision as it was. Otherwise it commits one receipt (undoable for RECEIPT_TTL seconds by the
     * same administrator) and one audit event, in the same transaction.
     *
     * @param string $expectedRevision the revision the administrator's page was built from
     * @param string $actor uid of the administrator who sent the command
     * @param string $action PolicyEventPayload::RECEIPT_ACTIONS member naming the route
     * @param list<array{uid:string, key:string, value:?string}> $cells key from PolicyEventPayload::userKey(); value
     *     '1' (allow), '0' (deny) or null (inherit: remove the override). An exact duplicate is dropped; the same cell
     *     with two values is refused.
     * @return array{revision:string, changed:int, receiptId:?string} committed revision, changed cell count, receipt
     * @throws \InvalidArgumentException for an invalid revision, cell, unknown user, or more than MAX_UIDS users or
     *     MAX_CELLS cells; nothing is written
     * @throws PolicyConflict when the expected revision is stale
     * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
     */
    public function applyCells(string $expectedRevision, string $actor, string $action, array $cells): array {
        if (!in_array($action, PolicyEventPayload::RECEIPT_ACTIONS, true) || !PolicyEventPayload::validUid($actor)) {
            throw new \InvalidArgumentException('Invalid command');
        }
        $cells = self::cells($cells);
        $uids = array_values(array_unique(array_column($cells, 'uid')));
        $receiptId = null;
        $changed = 0;
        $result = $this->runAdmin($expectedRevision, $uids, function () use ($uids): void {
            foreach ($uids as $uid) {
                $this->assertUser($uid);
            }
        }, function ($snapshot, array $current, string $commandId, PolicyOutbox $outbox) use ($cells, $actor, $action, &$receiptId, &$changed): bool {
            $changes = $this->applyLocal($cells);
            $changed = count($changes);
            $receiptId = null;
            if ($changes === []) {
                return false;
            }
            $revision = self::next($current['revision']);
            $receiptId = $outbox->add(PolicyEventsMapper::KIND_RECEIPT, PolicyEventPayload::receipt($actor, $action, $current['revision'], $revision, $changes));
            $outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, $action, $current['revision'], $revision, $receiptId, null, []));
            return true;
        });
        return ['revision' => $result['revision'], 'changed' => $changed, 'receiptId' => $receiptId];
    }

    /**
     * Undoes the cells of one receipt, as a new transaction and revision: each cell gets its exact raw value from
     * before the command back, including absence. Tokens and epochs revoked by the command are not restored, but a
     * cell that loses eligibility again revokes like any other change. Only the receipt's own administrator may undo
     * it, only within RECEIPT_TTL seconds and only while the policy is still at the revision that command committed:
     * any later change, to these cells or any other, is a conflict that touches nothing.
     *
     * @param string $receiptId id returned by applyCells()
     * @param string $expectedRevision the revision the receipt's command committed
     * @param string $actor uid of the administrator asking
     * @return array{revision:string, changed:int} committed revision and restored cell count
     * @throws \InvalidArgumentException for an invalid revision
     * @throws ReceiptNotFound for an unknown or invented receipt, or one created by another administrator
     * @throws ReceiptExpired for the caller's own receipt past RECEIPT_TTL
     * @throws PolicyConflict when the policy changed since the receipt's commit, or one of its users is gone
     * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
     */
    public function restoreReceipt(string $receiptId, string $expectedRevision, string $actor): array {
        DecimalCounter::validate($expectedRevision);
        $receipt = $this->receipt($receiptId, $actor);
        if ($receipt['revision'] !== $expectedRevision) {
            throw new PolicyConflict();
        }
        $cells = array_map(static fn (array $change): array => ['uid' => $change['uid'], 'key' => $change['key'], 'value' => $change['before']], $receipt['changes']);
        $uids = array_values(array_unique(array_column($cells, 'uid')));
        $changed = 0;
        $result = $this->runAdmin($expectedRevision, $uids, function () use ($uids): void {
            foreach ($uids as $uid) {
                if (!PolicyEventPayload::validUid($uid) || $this->users->get($uid) === null) {
                    // Writing preferences back for a deleted account would leave orphans; the state moved on.
                    throw new PolicyConflict();
                }
            }
        }, function ($snapshot, array $current, string $commandId, PolicyOutbox $outbox) use ($cells, $actor, $receiptId, &$changed): bool {
            $changes = $this->applyLocal($cells);
            $changed = count($changes);
            if ($changes === []) {
                return false;
            }
            $outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_UNDO,
                $current['revision'], self::next($current['revision']), null, $receiptId, $changes));
            return true;
        });
        return ['revision' => $result['revision'], 'changed' => $changed];
    }

    /**
     * The service switch from the admin page: same effect as setGlobalEnabled(), behind the expected revision, with
     * an audit event when it changes.
     *
     * @param string $expectedRevision the revision the administrator's page was built from
     * @param string $actor uid of the administrator
     * @param bool $enabled new state of the global service switch
     * @return array{revision:string, changed:bool}
     * @throws \InvalidArgumentException for an invalid revision
     * @throws PolicyConflict when the expected revision is stale
     * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
     */
    public function adminSetGlobalEnabled(string $expectedRevision, string $actor, bool $enabled): array {
        if (!PolicyEventPayload::validUid($actor)) {
            throw new \InvalidArgumentException('Invalid command');
        }
        $value = $enabled ? '1' : '0';
        $result = $this->runAdmin($expectedRevision, [], fn () => null,
            function ($snapshot, array $current, string $commandId, PolicyOutbox $outbox) use ($actor, $value): bool {
                $change = $this->writeCell(null, GrantPolicy::SERVICE_KEY, $value);
                if ($change === null) {
                    return false;
                }
                $outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_SERVICE,
                    $current['revision'], self::next($current['revision']), null, null, [$change]));
                return true;
            });
        return ['revision' => $result['revision'], 'changed' => $result['changed']];
    }

    /**
     * The revision a command running at $revision commits; an exhausted counter is a storage failure (503).
     *
     * @throws PolicyUnavailable when $revision is already the largest one a CAS may start from
     */
    public static function next(string $revision): string {
        try {
            return DecimalCounter::increment($revision);
        } catch (\InvalidArgumentException $e) {
            throw new PolicyUnavailable('Policy revision exhausted', previous: $e);
        }
    }

    private function change(?string $uid, string $key, ?string $value): void {
        $uids = $uid === null ? [] : [$uid];
        $this->runInternal($uids, function () use ($uid): void {
            if ($uid !== null) {
                $this->assertUser($uid);
            }
        }, fn (): bool => $this->writeCell($uid, $key, $value) !== null);
    }

    /** @throws \InvalidArgumentException for a user id the server does not know */
    private function assertUser(string $uid): void {
        if (!PolicyEventPayload::validUid($uid) || $this->users->get($uid) === null) {
            throw new \InvalidArgumentException('Unknown user');
        }
    }

    /**
     * Writes user cells under the mutex, reading each key's current raw values for all its users in one batch.
     *
     * @param list<array{uid:string, key:string, value:?string}> $cells validated, without duplicates
     * @return list<array{uid:string, key:string, before:?string, after:?string}> the cells that changed, in order
     */
    private function applyLocal(array $cells): array {
        $byKey = [];
        foreach ($cells as $cell) {
            $byKey[$cell['key']][] = $cell['uid'];
        }
        $current = [];
        foreach ($byKey as $key => $uids) {
            $current[$key] = $this->config->getUserValueForUsers(GrantPolicy::APP, (string)$key, $uids);
        }
        $changes = [];
        foreach ($cells as $cell) {
            $values = $current[$cell['key']];
            $before = array_key_exists($cell['uid'], $values) ? (string)$values[$cell['uid']] : null;
            $change = $this->writeCell($cell['uid'], $cell['key'], $cell['value'], $before);
            if ($change !== null) {
                $changes[] = $change;
            }
        }
        return $changes;
    }

    /**
     * Writes one raw value (null removes the key) when it differs from the stored one, and applies the legacy
     * revocation scope: switching the service off revokes everyone, and a user left without explicit eligibility is
     * signed out, including on an explicit repeated disable.
     *
     * @param string|false|null $before the raw value already read in this transaction (null = absent), or false to
     *     read it here
     * @return array{uid:?string, key:string, before:?string, after:?string}|null the change, or null for a no-op
     */
    private function writeCell(?string $uid, string $key, ?string $value, string|false|null $before = false): ?array {
        if ($before === false) {
            // Batch reads preserve presence and legacy MIXED values. Never materialize an inherited default.
            if ($uid === null) {
                $before = $this->appConfig->hasKey(GrantPolicy::APP, $key) ? $this->config->getAppValue(GrantPolicy::APP, $key) : null;
            } else {
                $values = $this->config->getUserValueForUsers(GrantPolicy::APP, $key, [$uid]);
                $before = array_key_exists($uid, $values) ? (string)$values[$uid] : null;
            }
        }
        $changed = $before !== $value;
        if ($changed) {
            if ($uid === null) {
                $this->config->setAppValue(GrantPolicy::APP, $key, (string)$value);
            } elseif ($value === null) {
                $this->config->deleteUserValue($uid, GrantPolicy::APP, $key);
            } else {
                $this->config->setUserValue($uid, GrantPolicy::APP, $key, $value);
            }
        }
        if ($key === GrantPolicy::SERVICE_KEY && $value === '0') {
            $this->oauth->deleteAll();
        } elseif ($key === GrantPolicy::ELIGIBLE_KEY && $uid !== null && ($value === '0' || ($changed && $value !== '1'))) {
            $this->oauth->deleteForUser($uid);
        }
        return $changed ? ['uid' => $uid, 'key' => $key, 'before' => $before, 'after' => $value] : null;
    }

    /**
     * Validates a whole command before anything runs: shapes, keys, values and limits.
     *
     * @param array<mixed> $cells candidate cells
     * @return list<array{uid:string, key:string, value:?string}> the distinct cells, in first-seen order
     * @throws \InvalidArgumentException for any invalid cell, a cell asked with two values, or a limit exceeded
     */
    private static function cells(array $cells): array {
        if ($cells === [] || !array_is_list($cells)) {
            throw new \InvalidArgumentException('Invalid command');
        }
        $distinct = [];
        $uids = [];
        foreach ($cells as $cell) {
            if (!is_array($cell) || !is_string($cell['uid'] ?? null) || !is_string($cell['key'] ?? null)
                || !array_key_exists('value', $cell) || !in_array($cell['value'], ['1', '0', null], true)
                || !PolicyEventPayload::validUid($cell['uid']) || !PolicyEventPayload::validUserKey($cell['key'])) {
                throw new \InvalidArgumentException('Invalid command');
            }
            $id = hash('sha256', $cell['uid'] . "\0" . $cell['key']);
            if (array_key_exists($id, $distinct)) {
                if ($distinct[$id]['value'] !== $cell['value']) {
                    throw new \InvalidArgumentException('Invalid command');
                }
                continue;
            }
            $distinct[$id] = ['uid' => $cell['uid'], 'key' => $cell['key'], 'value' => $cell['value']];
            $uids[$cell['uid']] = true;
            if (count($distinct) > self::MAX_CELLS || count($uids) > self::MAX_UIDS) {
                throw new \InvalidArgumentException('Invalid command');
            }
        }
        return array_values($distinct);
    }

    /**
     * Loads a receipt for its own administrator, checking ownership before expiry so another administrator's
     * receipt is indistinguishable from an unknown one.
     *
     * @return array{revision:string, changes:list<array{uid:string, key:string, before:?string, after:?string}>}
     * @throws ReceiptNotFound|ReceiptExpired|PolicyUnavailable
     */
    private function receipt(string $receiptId, string $actor): array {
        if (!PolicyEventsMapper::validId($receiptId)) {
            throw new ReceiptNotFound();
        }
        try {
            $event = $this->events->find($receiptId);
            $payload = $event === null || $event['kind'] !== PolicyEventsMapper::KIND_RECEIPT ? null
                : PolicyEventPayload::validate(PolicyEventsMapper::KIND_RECEIPT, $event['payload']);
        } catch (\UnexpectedValueException $e) {
            throw new PolicyUnavailable('Invalid stored receipt', previous: $e);
        } catch (DatabaseException $e) {
            throw new PolicyUnavailable(previous: $e);
        }
        if ($payload === null || $payload['actor'] !== $actor) {
            throw new ReceiptNotFound();
        }
        if ($this->time->getTime() >= $event['createdAt'] + self::RECEIPT_TTL) {
            throw new ReceiptExpired();
        }
        return ['revision' => $payload['revision'], 'changes' => $payload['changes']];
    }

    private function run(array $uids, Closure $prepare, Closure $apply, ?string $expected): array {
        if (!$this->usable) {
            throw new PolicyUnavailable('Policy context was abandoned');
        }
        if ($this->db->inTransaction()) {
            throw new PolicyUnavailable('Policy writer requires a top-level transaction');
        }
        $uids = array_values(array_unique($uids));
        $commandId = bin2hex(random_bytes(16));
        for ($attempt = 0; ; ++$attempt) {
            $owned = false;
            $committing = false;
            $committed = false;
            $result = null;
            $failure = null;
            try {
                $this->clearCaches($uids);
                $observed = $this->state->state();
                if ($expected !== null && $expected !== $observed['revision']) {
                    throw new PolicyConflict();
                }
                $snapshot = $prepare($observed, $commandId);
                if ($this->db->inTransaction()) {
                    throw new PolicyUnavailable('External admission opened a transaction');
                }
                // Some drivers may throw after beginning a transaction: the catch also checks inTransaction().
                $owned = true;
                $this->db->beginTransaction();
                $this->state->acquire();
                $current = $this->state->state();
                if ($current['revision'] !== $observed['revision'] || $current['auth_epoch'] !== $observed['auth_epoch']) {
                    throw new SnapshotInvalidated();
                }
                // Local reads must not reuse config populated by remote admission.
                $this->clearCaches($uids);
                // A fresh outbox per attempt: a rolled-back attempt leaves no event behind, and ids repeat on retry.
                $outbox = new PolicyOutbox($commandId);
                $changed = $apply($snapshot, $current, $commandId, $outbox);
                if (!is_bool($changed)) {
                    throw new \LogicException('Policy command must report whether raw policy changed');
                }
                if ($changed) {
                    try {
                        DecimalCounter::validate($current['revision']);
                    } catch (\InvalidArgumentException $e) {
                        throw new PolicyUnavailable('Policy revision exhausted', previous: $e);
                    }
                    $this->state->compareAndSet($current['revision']);
                }
                foreach ($outbox->events() as $event) {
                    $this->events->append($event['id'], $event['kind'], $event['payload']);
                }
                $revision = $this->state->state()['revision'];
                $committing = true;
                $this->db->commit();
                $committed = true;
                $owned = false;
                $result = ['revision' => $revision, 'changed' => $changed, 'commandId' => $commandId];
            } catch (Throwable $e) {
                $failure = $this->failure($e, $owned, $committing, $attempt, $expected);
            }
            $this->settle($uids, $committed, $failure);
            if ($result !== null) {
                return $result;
            }
            if ($failure !== null) {
                throw $failure;
            }
            // At most two waits of 100 ms: total app-owned waiting stays below the 1 s budget.
            usleep(random_int(25000, 100000));
        }
    }

    /**
     * Rolls back an attempt the writer owns and classifies its failure.
     *
     * @return Throwable|null the exception to surface, or null when the whole attempt may be replayed
     */
    private function failure(Throwable $e, bool $owned, bool $committing, int $attempt, ?string $expected): ?Throwable {
        $retry = $e instanceof SnapshotInvalidated || ($owned && self::retryable($e));
        if ($owned) {
            try {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            } catch (Throwable $rollback) {
                $this->usable = false;
                return new PolicyUnavailable('Policy rollback failed', $committing, $rollback);
            }
        }
        if ($committing && !$retry) {
            $this->usable = false;
            return new PolicyUnavailable('Policy commit outcome is uncertain; reload state', true, $e);
        }
        if (!$retry) {
            if ($e instanceof DatabaseException || $e instanceof \Doctrine\DBAL\Exception || $e instanceof \Doctrine\DBAL\Driver\Exception) {
                return new PolicyUnavailable(previous: $e);
            }
            return $e;
        }
        if ($attempt >= 2) {
            if ($e instanceof SnapshotInvalidated && $expected !== null) {
                return new PolicyConflict();
            }
            return new PolicyUnavailable('Policy storage busy; retry later', previous: $e);
        }
        return null;
    }

    /**
     * The cache clear that closes every attempt. If it fails the context is abandoned, but the outcome it reports
     * stays honest: after a commit the write is durable, so the caller is told the state is uncertain (reload), never
     * merely unavailable; and an attempt's own failure stays chained instead of being replaced by the cache error.
     *
     * @throws PolicyUnavailable when the caches cannot be cleared
     */
    private function settle(array $uids, bool $committed, ?Throwable $failure): void {
        try {
            $this->clearCaches($uids);
        } catch (PolicyUnavailable $cache) {
            if ($committed) {
                throw new PolicyUnavailable('Policy committed, but its caches could not be cleared; reload state', true, $cache->getPrevious() ?? $cache);
            }
            if ($failure !== null) {
                throw new PolicyUnavailable($cache->getMessage(), $failure instanceof PolicyUnavailable && $failure->uncertain, $failure);
            }
            throw $cache;
        }
    }

    private function clearCaches(array $uids): void {
        try {
            foreach ($uids as $uid) {
                $this->userConfig->clearCache($uid, false);
            }
            $this->appConfig->clearCache(false);
        } catch (Throwable $e) {
            $this->usable = false;
            throw new PolicyUnavailable('Cannot clear policy caches; context abandoned', previous: $e);
        }
    }

    /** Only explicit deadlock/timeout classifications are safe to replay, including at begin/commit. */
    private static function retryable(Throwable $error): bool {
        if ($error instanceof DatabaseException) {
            return in_array($error->getReason(), [DatabaseException::REASON_DEADLOCK, DatabaseException::REASON_LOCK_WAIT_TIMEOUT], true);
        }
        // DBAL driver failures can escape core's wrapper at transaction boundaries.
        if ($error instanceof \Doctrine\DBAL\Exception\DeadlockException || $error instanceof \Doctrine\DBAL\Exception\LockWaitTimeoutException) {
            return true;
        }
        if ($error instanceof \Doctrine\DBAL\Driver\Exception) {
            $sqlState = $error->getSQLState();
            return in_array($sqlState, ['40001', '40P01', '55P03'], true)
                || ($sqlState === 'HY000' && in_array($error->getCode(), [5, 6, 1205, 1213], true));
        }
        return false;
    }
}
