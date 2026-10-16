<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service\Policy;

use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removal of a deleted account's resource scope (contracts B.3, F3), qualified by the policy revision so a scope saved
 * after the deletion started is never removed. Each removal is a writer command under the global mutex that advances
 * the revision when it removes anything; every read of the account or the directory happens before the mutex, and no
 * pre-event handler takes it.
 *
 * | Signal | Under the mutex |
 * | --- | --- |
 * | UserDeletedEvent with the revision R_b read by its BeforeUserDeletedEvent, both outside a transaction | remove the user's scopes with policy_rev <= R_b; later ones stay |
 * | UserDeletedEvent without R_b (its Before ran inside a transaction or failed), or itself inside a transaction | nothing: the job decides |
 * | the account's Before without an After (the deletion failed or was rolled back) | nothing: there is no event |
 * | job: the account was seen gone twice, at least ABSENCE_WINDOW seconds apart, and a third fresh read in the command's external phase still finds it gone | remove the scopes with policy_rev <= the revision still equal to the one read |
 * | job: the first sighting of an absent account | nothing: its time is kept (one lazy app config value), the removal waits |
 * | job: the account exists, or cannot be read (any error), or the removal fails | nothing, and its kept time is erased: the count starts over |
 *
 * The job is slower than the event on purpose. A backend that answers "no such user" while it is merely unreachable
 * would lift the restriction of an account that exists, which is the one mistake that matters; keeping a stale scope is
 * harmless, since an account recreated under the id is only restricted. So the job removes only after two sightings of
 * the absence a day apart, with nothing observed in between that says otherwise. The event with R_b is a strong proof
 * from the core itself and stays immediate.
 *
 * Declared behavior: while the account exists its scope is kept. An account deleted and recreated under the same id,
 * with the cleanup not run in between (a deferred event, then a recreation before the job), keeps the old restriction:
 * it only narrows, and it is never lifted for lack of proof. A scope saved for the recreated account after the cleanup
 * revision was read is a later revision and is never removed by it. There is no unqualified delete by user and no
 * authorization generation in these tables.
 */
class ScopeCleanup {
    /** Users the job decides per run; the rest wait for the next run. */
    public const RECONCILE_BATCH = 200;
    /** App config key (lazy) holding the user_key after which the next job run starts. */
    public const CURSOR_KEY = 'scope_cleanup_cursor';
    /** App config key (lazy): JSON object, user_key => time of the first sighting of the account's absence. */
    public const ABSENCE_KEY = 'scope_absent_since';
    /** Seconds between the two sightings of an absent account that the job needs before it removes a scope. */
    public const ABSENCE_WINDOW = 86400;

    public function __construct(
        private PolicyWriter $writer,
        private ResourceScopeMapper $scopes,
        private IUserManager $users,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
        private ITimeFactory $time,
    ) {}

    /**
     * UserDeletedEvent, on time: removes the scopes the account had when its deletion started.
     *
     * @param string $uid the deleted account
     * @param string $revision R_b, the revision its BeforeUserDeletedEvent read in autocommit
     * @return list<string> the modules whose scope was removed
     * @throws \InvalidArgumentException for an invalid user id or revision
     * @throws PolicyUnavailable when the storage fails, a stored row is invalid, or the outcome is uncertain: nothing is
     *     removed on the storage failures, and the job retries
     */
    public function deleted(string $uid, string $revision): array {
        if (!PolicyEventPayload::validUid($uid)) {
            throw new \InvalidArgumentException('Invalid user');
        }
        DecimalCounter::validate($revision, DecimalCounter::MAX);
        $removed = [];
        $this->writer->runInternal([$uid], static fn () => null, function () use ($uid, $revision, &$removed): bool {
            $removed = $this->removeLocal($uid, $revision);
            return $removed !== [];
        });
        if ($removed !== []) {
            $this->logger->info('MCP resource scope removed with its deleted account', ['app' => 'mcp', 'target' => $uid, 'modules' => $removed, 'result' => 'removed']);
        }
        return $removed;
    }

    /**
     * The job's pass over the users that still have a scope, for the deletions whose event was deferred, lost or never
     * delivered. A user that exists is not touched and takes no mutex; one that is gone is only noted the first time,
     * and removed once the absence was seen again at least ABSENCE_WINDOW later, under the mutex and only while the
     * revision is the one read, so a scope saved in between replays the attempt (and, with the account back, keeps it).
     * Seeing the account exist, failing to read it or failing to remove erases its note. Pages of $limit follow a
     * cursor, so users that keep failing never hold the batch; it wraps around when a page comes back short. One user
     * that fails, or a row whose id does not match its digest, is skipped and logged without stopping the others.
     *
     * The diagnostic of the pass is logged: how many users were kept because the account exists, how many are waiting
     * for their second sighting, how many stored scopes could not be verified or failed (they block their user's
     * cleanup and the user's scope stays), and how many users keep orphan item rows (items without their mode, which
     * have no effect and are left in place).
     *
     * @param int $limit most users decided
     * @return int users whose scope was removed
     */
    public function reconcile(int $limit = self::RECONCILE_BATCH): int {
        try {
            $cursor = $this->appConfig->getValueString('mcp', self::CURSOR_KEY, '', true);
            $page = $this->scopes->usersWithModes($limit, $cursor);
            $fromStart = $cursor === '';
            if ($page === [] && $cursor !== '') {
                $page = $this->scopes->usersWithModes($limit);
                $fromStart = true;
            }
            $this->appConfig->setValueString('mcp', self::CURSOR_KEY,
                count($page) < $limit ? '' : $page[count($page) - 1]['userKey'], true);
        } catch (Throwable $e) {
            $this->logger->warning('Cannot list the users with a resource scope', ['app' => 'mcp', 'exception' => $e]);
            return 0;
        }
        // A pass that starts at the beginning and ends short has seen every user with a scope: notes of anyone else go.
        $complete = $fromStart && count($page) < $limit;
        $marks = $this->marks();
        $cleaned = 0;
        $kept = 0;
        $waiting = 0;
        $blocked = 0;
        foreach ($page as ['userKey' => $userKey, 'userId' => $uid]) {
            try {
                if (!PolicyEventPayload::validUid($uid) || IdentityKey::user($uid) !== $userKey) {
                    ++$blocked;
                    $this->logger->warning('A stored resource scope names an id that does not match its key; it stays', ['app' => 'mcp']);
                    continue;
                }
                // A live account takes no mutex: the proof below is read outside it, as the writer's external phase is.
                if ($this->exists($uid)) {
                    unset($marks[$userKey]);
                    ++$kept;
                    continue;
                }
                $now = $this->time->getTime();
                $since = $marks[$userKey] ?? null;
                if ($since === null || $now < $since + self::ABSENCE_WINDOW) {
                    $marks[$userKey] ??= $now;
                    ++$waiting;
                    continue;
                }
                $removed = [];
                $existsNow = false;
                $this->writer->runInternal([$uid], fn (): bool => $this->exists($uid),
                    function (bool $exists, array $current) use ($uid, &$removed, &$existsNow): bool {
                        if ($exists) {
                            $existsNow = true;
                            return false;
                        }
                        // The revision the proof was read at is the one still current here, or the writer replayed.
                        $removed = $this->removeLocal($uid, $current['revision']);
                        return $removed !== [];
                    });
                if ($existsNow) {
                    unset($marks[$userKey]);
                    ++$kept;
                    continue;
                }
                unset($marks[$userKey]);
                if ($removed !== []) {
                    ++$cleaned;
                    $this->logger->info('MCP resource scope removed with its deleted account', ['app' => 'mcp', 'target' => $uid, 'modules' => $removed, 'result' => 'removed']);
                }
            } catch (Throwable $e) {
                // An unreadable account or a failed removal erases the note: the absence has to be seen again.
                unset($marks[$userKey]);
                ++$blocked;
                $this->logger->warning('A resource scope of a user stays for the next run', ['app' => 'mcp', 'target' => $uid, 'exception' => $e]);
            }
        }
        if ($complete) {
            $marks = array_intersect_key($marks, array_flip(array_column($page, 'userKey')));
        }
        $this->storeMarks($marks);
        if ($page !== []) {
            $this->diagnose(count($page), $kept, $waiting, $blocked, $cleaned);
        }
        return $cleaned;
    }

    /**
     * The notes of the first sighting of an absent account, by user_key. An unreadable or malformed value is no notes at
     * all, which only makes every count start over: it can delay a removal, never bring one forward.
     *
     * @return array<string, int>
     */
    private function marks(): array {
        try {
            $raw = $this->appConfig->getValueString('mcp', self::ABSENCE_KEY, '', true);
            $decoded = $raw === '' ? [] : json_decode($raw, true, 3);
        } catch (Throwable) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $marks = [];
        foreach ($decoded as $key => $time) {
            if (is_string($key) && preg_match('/^[0-9a-f]{64}$/', $key) === 1 && is_int($time) && $time > 0) {
                $marks[$key] = $time;
            }
        }
        return $marks;
    }

    /** @param array<string, int> $marks */
    private function storeMarks(array $marks): void {
        try {
            $this->appConfig->setValueString('mcp', self::ABSENCE_KEY, $marks === [] ? '' : json_encode($marks, JSON_THROW_ON_ERROR), true);
        } catch (Throwable $e) {
            $this->logger->warning('Cannot keep the notes of the absent accounts; their count starts over', ['app' => 'mcp', 'exception' => $e]);
        }
    }

    /**
     * Under the mutex: the user's scopes saved at or before $revision, each with its items, and nothing newer.
     *
     * @return list<string> the modules removed
     * @throws PolicyUnavailable when a stored row is invalid or inconsistent: nothing was removed
     */
    private function removeLocal(string $uid, string $revision): array {
        try {
            return $this->scopes->removeUpTo($uid, $revision);
        } catch (\UnexpectedValueException $e) {
            throw new PolicyUnavailable('Invalid stored resource scope', previous: $e);
        }
    }

    /**
     * A fresh read of the account, never under the mutex: the user object and the backends' own existence answer must
     * both say it is gone. Anything else (an account, a disabled one, an error) means it exists for this purpose.
     *
     * @throws PolicyUnavailable when the account cannot be read
     */
    private function exists(string $uid): bool {
        try {
            return $this->users->get($uid) !== null || $this->users->userExists($uid);
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot read the account of a resource scope', previous: $e);
        }
    }

    private function diagnose(int $seen, int $kept, int $waiting, int $blocked, int $cleaned): void {
        try {
            $orphans = $this->scopes->orphanItemUsers();
        } catch (Throwable) {
            $orphans = null;
        }
        $this->logger->info('MCP resource scope cleanup pass', ['app' => 'mcp', 'users' => $seen, 'kept_account_exists' => $kept,
            'waiting_second_sighting' => $waiting, 'blocked_or_failed' => $blocked, 'cleaned' => $cleaned, 'orphan_item_users' => $orphans]);
    }
}
