<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service\Policy;

use Closure;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Service\GrantPolicy;
use OCP\Config\IUserConfig;
use OCP\DB\Exception as DatabaseException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use Throwable;

/**
 * Owns the top-level transaction. Trusted app commands prepare remote observations outside the mutex and
 * perform only local statements inside it. All participants use the server's singleton IDBConnection.
 */
class PolicyWriter {
    private bool $usable = true;

    public function __construct(
        private IDBConnection $db,
        private PolicyStateMapper $state,
        private IConfig $config,
        private IUserConfig $userConfig,
        private IAppConfig $appConfig,
        private IUserManager $users,
        private OAuthStore $oauth,
    ) {}

    /**
     * Administrative admission; the HTTP adapter supplies the required canonical revision.
     * The local callback returns whether raw policy changed; it may throw SnapshotInvalidated when an epoch,
     * cause set or single-use row differs from its external observation. A logical command id survives retries.
     * @param list<string> $uids every user whose official config cache may be touched
     * @param Closure(array,string):mixed $prepare remote work, outside any transaction
     * @param Closure(mixed,array,string):bool $apply local work only, under the global mutex
     * @return array{revision:string, changed:bool, commandId:string}
     */
    public function runAdmin(string $expectedRevision, array $uids, Closure $prepare, Closure $apply): array {
        DecimalCounter::validate($expectedRevision);
        if (count(array_unique($uids)) > 50) {
            throw new \InvalidArgumentException('Too many policy users');
        }
        return $this->run($uids, $prepare, $apply, $expectedRevision);
    }

    /**
     * Internal commands (including processed directory events) still validate their observed revision and use CAS.
     * Directory observations alone return false and never advance revision.
     * @param list<string> $uids
     * @param Closure(array,string):mixed $prepare
     * @param Closure(mixed,array,string):bool $apply
     * @return array{revision:string, changed:bool, commandId:string}
     */
    public function runInternal(array $uids, Closure $prepare, Closure $apply): array {
        return $this->run($uids, $prepare, $apply, null);
    }

    public function setGlobalEnabled(bool $enabled): void {
        $this->change(null, GrantPolicy::SERVICE_KEY, $enabled ? '1' : '0');
    }

    public function setEligible(string $uid, bool $enabled): void {
        $this->change($uid, GrantPolicy::ELIGIBLE_KEY, $enabled ? '1' : '0');
    }

    public function setConnected(string $uid, bool $enabled): void {
        $this->change($uid, GrantPolicy::CONNECTED_KEY, $enabled ? '1' : '0');
    }

    public function setGrant(string $uid, string $module, string $operation, bool $enabled): void {
        if (!GrantPolicy::inCatalog($module, $operation)) {
            throw new \InvalidArgumentException('Unknown module or operation');
        }
        $this->change($uid, 'grant_' . $module . '_' . $operation, $enabled ? '1' : '0');
    }

    /** Removes an individual override through the same transaction; absence remains absence. */
    public function resetOverride(string $uid, string $module, string $operation): void {
        if (!GrantPolicy::inCatalog($module, $operation)) {
            throw new \InvalidArgumentException('Unknown module or operation');
        }
        $this->change($uid, 'grant_' . $module . '_' . $operation, null);
    }

    private function change(?string $uid, string $key, ?string $value): void {
        $uids = $uid === null ? [] : [$uid];
        $this->runInternal($uids, function () use ($uid): void {
            if ($uid !== null && ($uid === '' || str_contains($uid, "\0") || !mb_check_encoding($uid, 'UTF-8') || $this->users->get($uid) === null)) {
                throw new \InvalidArgumentException('Unknown user');
            }
        }, function () use ($uid, $key, $value): bool {
            // Batch reads preserve presence and legacy MIXED values. Never materialize an inherited default.
            if ($uid === null) {
                $present = $this->appConfig->hasKey(GrantPolicy::APP, $key);
                $before = $present ? $this->config->getAppValue(GrantPolicy::APP, $key) : null;
            } else {
                $values = $this->config->getUserValueForUsers(GrantPolicy::APP, $key, [$uid]);
                $present = array_key_exists($uid, $values);
                $before = $present ? $values[$uid] : null;
            }
            $changed = $value === null ? $present : (!$present || $before !== $value);
            if ($changed) {
                if ($uid === null) {
                    $this->config->setAppValue(GrantPolicy::APP, $key, $value);
                } elseif ($value === null) {
                    $this->config->deleteUserValue($uid, GrantPolicy::APP, $key);
                } else {
                    $this->config->setUserValue($uid, GrantPolicy::APP, $key, $value);
                }
            }
            // Preserve the legacy revocation scope, including an explicit repeated disable.
            if ($value === '0' && $key === GrantPolicy::SERVICE_KEY) {
                $this->oauth->deleteAll();
            } elseif ($value === '0' && $key === GrantPolicy::ELIGIBLE_KEY) {
                $this->oauth->deleteForUser($uid);
            }
            return $changed;
        });
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
            $retry = false;
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
                $changed = $apply($snapshot, $current, $commandId);
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
                $revision = $this->state->state()['revision'];
                $committing = true;
                $this->db->commit();
                $owned = false;
                return ['revision' => $revision, 'changed' => $changed, 'commandId' => $commandId];
            } catch (Throwable $e) {
                $retry = $e instanceof SnapshotInvalidated || ($owned && self::retryable($e));
                if ($owned) {
                    try {
                        if ($this->db->inTransaction()) {
                            $this->db->rollBack();
                        }
                    } catch (Throwable $rollback) {
                        $this->usable = false;
                        throw new PolicyUnavailable('Policy rollback failed', $committing, $rollback);
                    }
                }
                if ($committing && !$retry) {
                    $this->usable = false;
                    throw new PolicyUnavailable('Policy commit outcome is uncertain; reload state', true, $e);
                }
                if (!$retry) {
                    if ($e instanceof DatabaseException || $e instanceof \Doctrine\DBAL\Exception || $e instanceof \Doctrine\DBAL\Driver\Exception) {
                        throw new PolicyUnavailable(previous: $e);
                    }
                    throw $e;
                }
                if ($attempt >= 2) {
                    if ($e instanceof SnapshotInvalidated && $expected !== null) {
                        throw new PolicyConflict();
                    }
                    throw new PolicyUnavailable('Policy storage busy; retry later', previous: $e);
                }
            } finally {
                $this->clearCaches($uids);
            }
            // At most two waits of 100 ms: total app-owned waiting stays below the 1 s budget.
            usleep(random_int(25000, 100000));
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
