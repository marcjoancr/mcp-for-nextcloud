<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service\Policy;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The final transitions of the GID lifecycle (contracts §3.3.5, §3.3.6), each a writer command under the global mutex
 * that advances the revision when it changes anything (coordinator clarification 1). The pre-event transition to
 * pending_delete is not here: it runs without the mutex (GroupLifeMapper::markPendingDelete()).
 *
 * | Signal | Under the mutex |
 * | --- | --- |
 * | GroupDeletedEvent with the gen its Before wrote | purge the group's rows and lifecycle; another gen: no-op |
 * | GroupCreatedEvent with the revision R_c read by its Before | policy_rev <= R_c: purge; > R_c: keep |
 * | GroupCreatedEvent without a usable R_c | needs_confirm, rows kept |
 * | a deletion or creation captured inside a caller's transaction, or whose Before failed | needs_confirm, rows kept |
 * | job: pending_delete, group gone | purge, if the gen is still the one read |
 * | job: pending_delete, group exists | needs_confirm |
 *
 * Nothing here ever deletes rows on an unconfirmed signal, and nothing makes a group grant again: only an
 * administrator's save (ticket 08) brings a lifecycle back to active.
 */
class GroupLifecycle {
    /** Lifecycles the job decides per run; the rest wait for the next run. */
    public const RECONCILE_BATCH = 200;

    public function __construct(
        private PolicyWriter $writer,
        private GroupLifeMapper $life,
        private GroupGrantsMapper $grants,
        private GroupSourceClassifier $classifier,
        private LoggerInterface $logger,
    ) {}

    /**
     * GroupDeletedEvent: purges the group's policy only if its lifecycle still carries the gen the Before wrote, so
     * a late event never removes a policy saved since (which got a new gen).
     *
     * @param string $gid the deleted group
     * @param string $gen the gen its BeforeGroupDeletedEvent wrote
     * @return bool whether the policy was purged
     * @throws PolicyUnavailable when the storage fails, or the outcome is uncertain
     */
    public function deleted(string $gid, string $gen): bool {
        $purged = false;
        $this->writer->runInternal([], static fn () => null, function () use ($gid, $gen, &$purged): bool {
            return $purged = $this->purgeLocal($gid, $gen);
        });
        return $purged;
    }

    /**
     * GroupCreatedEvent of a group that has a lifecycle. With $revision (R_c, read by its BeforeGroupCreatedEvent for
     * this very id), a policy saved at or before R_c belongs to an earlier group of the same id and is purged, while
     * one saved after the creation started is kept. Without it the policy is kept but suspended (needs_confirm).
     *
     * @param string $gid the created group
     * @param string|null $revision R_c, or null when it could not be captured or names another id
     * @throws PolicyUnavailable when the storage fails, or the outcome is uncertain
     */
    public function created(string $gid, ?string $revision): void {
        if ($revision !== null) {
            DecimalCounter::validate($revision, DecimalCounter::MAX);
        }
        if ($this->read($gid) === null) {
            return;
        }
        $this->writer->runInternal([], static fn () => null, function () use ($gid, $revision): bool {
            $row = $this->life->get($gid);
            if ($row === null) {
                return false;
            }
            if ($revision === null) {
                return $this->needsConfirmLocal($gid);
            }
            return DecimalCounter::compare($row['policyRev'], $revision) <= 0 && $this->purgeLocal($gid, $row['gen']);
        });
    }

    /**
     * A signal that cannot be trusted to date or confirm anything (contracts §3.3.6): captured inside a caller's
     * transaction, or one whose Before could not record its gen. The policy is kept and stops granting until an
     * administrator confirms it; it is never deleted and no GroupDeletedEvent is simulated.
     *
     * @param string $gid the group of the signal
     * @return bool whether a lifecycle moved to needs_confirm
     * @throws PolicyUnavailable when the storage fails, or the outcome is uncertain
     */
    public function needsConfirm(string $gid): bool {
        if ($this->read($gid) === null) {
            return false;
        }
        $moved = false;
        $this->writer->runInternal([], static fn () => null, function () use ($gid, &$moved): bool {
            return $moved = $this->needsConfirmLocal($gid);
        });
        return $moved;
    }

    /**
     * The job's pass over pending_delete lifecycles whose GroupDeletedEvent never came: a fresh existence read in the
     * external phase, then, under the mutex and only for the gen read, a purge (the group is gone) or needs_confirm
     * (the deletion failed, or the group was recreated with its signal lost). Idempotent by gen; one group that fails
     * never stops the others. If the job does not run, nothing grants outside active anyway.
     *
     * @param int $limit most lifecycles decided
     * @return int lifecycles changed
     */
    public function reconcile(int $limit = self::RECONCILE_BATCH): int {
        try {
            $rows = $this->life->pendingDeletes($limit);
        } catch (Throwable $e) {
            $this->logger->warning('Cannot list the pending group deletions', ['app' => 'mcp', 'exception' => $e]);
            return 0;
        }
        $changed = 0;
        foreach ($rows as $row) {
            try {
                $result = $this->writer->runInternal([], fn (): bool => $this->classifier->exists($row['groupId']),
                    function (bool $exists) use ($row): bool {
                        $current = $this->life->get($row['groupId']);
                        if ($current === null || $current['gen'] !== $row['gen'] || $current['state'] !== GroupLifeMapper::PENDING_DELETE) {
                            return false;
                        }
                        return $exists ? $this->needsConfirmLocal($row['groupId']) : $this->purgeLocal($row['groupId'], $row['gen']);
                    });
                $changed += $result['changed'] ? 1 : 0;
            } catch (Throwable $e) {
                $this->logger->warning('Pending group deletion left for the next run', ['app' => 'mcp', 'target' => $row['groupId'], 'exception' => $e]);
            }
        }
        return $changed;
    }

    /**
     * The lifecycle read by a local SELECT, so the irrelevant signals (groups the app holds no policy for) never take
     * the mutex.
     *
     * @throws PolicyUnavailable when it cannot be read
     */
    private function read(string $gid): ?array {
        try {
            return $this->life->get($gid);
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot read the group lifecycle', previous: $e);
        }
    }

    /** Under the mutex: the group's rows and lifecycle, only while it carries $gen; both go or neither does. */
    private function purgeLocal(string $gid, string $gen): bool {
        if (($this->life->get($gid)['gen'] ?? null) !== $gen) {
            return false;
        }
        $this->grants->deleteGroup($gid);
        if (!$this->life->delete($gid, $gen)) {
            // Not reachable under the mutex; never commit the rows' delete without the lifecycle's.
            throw new SnapshotInvalidated();
        }
        return true;
    }

    /** Under the mutex: active or pending_delete -> needs_confirm with a new gen; rows untouched. */
    private function needsConfirmLocal(string $gid): bool {
        $row = $this->life->get($gid);
        if ($row === null || $row['state'] === GroupLifeMapper::NEEDS_CONFIRM) {
            return false;
        }
        return $this->life->transition($gid, $row['gen'], GroupLifeMapper::NEEDS_CONFIRM, bin2hex(random_bytes(16)));
    }
}
