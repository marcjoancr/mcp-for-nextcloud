<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Listener;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Service\Policy\CauseProcessor;
use OCA\Mcp\Service\Policy\GroupSourceClassifier;
use OCA\Mcp\Service\Policy\PolicyResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\BeforeUserRemovedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IUser;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Observes the user events of contracts §3.3.2 without ever being a fence: it never throws into the core, never
 * takes the policy mutex in a pre-event and never refuses or delays a native operation. Inside a caller's transaction
 * it runs no SQL at all: the fact is kept in memory and reconciled later without a date (§3.3.6).
 *
 * - BeforeUserDeletedEvent(U), when U holds a credential or may be eligible: records the account cause with a new
 *   random gen, and marks U as being deleted in this process.
 * - BeforeUserRemovedEvent(G, U), unless U is being deleted: when G is a trusted group whose active policy grants
 *   eligibility, U is still in G and would not be eligible without G, records the cause (U, G) with a new gen. The
 *   duplicate Before of the same native operation (User::delete wraps Group::removeUser) reuses that gen.
 * - UserRemovedEvent(G, U): the protocol for the cause (U, G) with the captured gen, and nothing else.
 * - UserDeletedEvent(U): the protocol for the account cause with the captured gen (account gone: cut).
 *
 * A read that fails while testing relevance records the cause anyway: the reconciler repeats the test. A cause whose
 * After never arrives (a failed or interrupted operation) stays pending for the preflight and the reconciler (Q1).
 */
class UserCauseListener implements IEventListener {
    /** @var array<string, ?string> uid => gen of the account cause recorded by its Before (null: none recorded) */
    private array $deleting = [];
    /** @var array<string, array{uid:string, gid:string, gen:string}> pair => the cause recorded by its Before */
    private array $removals = [];

    public function __construct(
        private CauseProcessor $causes,
        private RecheckMapper $rechecks,
        private OAuthStore $store,
        private GroupGrantsMapper $grants,
        private GroupLifeMapper $life,
        private GroupSourceClassifier $classifier,
        private PolicyResolver $resolver,
        private IDBConnection $db,
        private ITimeFactory $time,
        private LoggerInterface $logger,
    ) {}

    public function handle(Event $event): void {
        try {
            if ($event instanceof BeforeUserDeletedEvent) {
                $this->beforeDelete($event->getUser());
            } elseif ($event instanceof BeforeUserRemovedEvent) {
                $this->beforeRemove($event->getGroup(), $event->getUser());
            } elseif ($event instanceof UserRemovedEvent) {
                $this->afterRemove($event->getGroup(), $event->getUser());
            } elseif ($event instanceof UserDeletedEvent) {
                $this->afterDelete($event->getUser());
            }
        } catch (Throwable $e) {
            try {
                $this->logger->warning('Recheck cause left to the reconciler', ['app' => 'mcp', 'exception' => $e]);
            } catch (Throwable) {
            }
        }
    }

    private function beforeDelete(IUser $user): void {
        $uid = $user->getUID();
        if ($this->db->inTransaction()) {
            $this->deleting[$uid] = null;
            $this->causes->deferUndated($uid);
            return;
        }
        $this->causes->flush();
        // A new native operation: always a new gen (contracts §3.3.1).
        $this->deleting[$uid] = null;
        if (!$this->accountRelevant($uid)) {
            return;
        }
        $gen = bin2hex(random_bytes(16));
        $this->rechecks->record($uid, RecheckMapper::REASON_ACCOUNT, null, $gen, $this->time->getTime());
        $this->deleting[$uid] = $gen;
    }

    private function beforeRemove(IGroup $group, IUser $user): void {
        $uid = $user->getUID();
        if (array_key_exists($uid, $this->deleting)) {
            // Part of the account deletion, whose account cause covers it (§3.3.3).
            return;
        }
        if ($this->db->inTransaction()) {
            $this->causes->deferUndated($uid);
            return;
        }
        $this->causes->flush();
        $gid = $group->getGID();
        $pair = self::pair($uid, $gid);
        $open = $this->removals[$pair]['gen'] ?? null;
        if ($open !== null && $this->stillPending($uid, $gid, $open)) {
            // The duplicate Before of the same native operation reuses its gen; nothing consumed it in between.
            return;
        }
        unset($this->removals[$pair]);
        if (!$this->removalRelevant($group, $user)) {
            return;
        }
        $gen = bin2hex(random_bytes(16));
        $this->rechecks->record($uid, RecheckMapper::REASON_GROUP, $gid, $gen, $this->time->getTime());
        $this->removals[$pair] = ['uid' => $uid, 'gid' => $gid, 'gen' => $gen];
    }

    private function afterRemove(IGroup $group, IUser $user): void {
        $pair = self::pair($user->getUID(), $group->getGID());
        $captured = $this->removals[$pair] ?? null;
        unset($this->removals[$pair]);
        if (!$this->db->inTransaction()) {
            $this->causes->flush();
        }
        if ($captured === null) {
            // Not relevant, already covered by the account cause, or the duplicate After of a consumed cause.
            return;
        }
        $this->causes->after($captured['uid'], IdentityKey::group($captured['gid']), $captured['gen']);
    }

    private function afterDelete(IUser $user): void {
        $uid = $user->getUID();
        $gen = $this->deleting[$uid] ?? null;
        unset($this->deleting[$uid]);
        if (!$this->db->inTransaction()) {
            $this->causes->flush();
        }
        if ($gen === null) {
            // Irrelevant at its Before, or captured inside a transaction (already deferred): nothing dated to do.
            return;
        }
        $this->causes->after($uid, IdentityKey::accountCause(), $gen);
    }

    /** U holds a code or grant row, or is not known to be ineligible. A failed read is relevant. */
    private function accountRelevant(string $uid): bool {
        try {
            return $this->store->hasCredentials($uid) || $this->eligible($uid, []) !== false;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * G grants eligibility through an active, trusted policy, U is still in G (or that is unknown) and U would not
     * be eligible without G. A failed read is relevant.
     */
    private function removalRelevant(IGroup $group, IUser $user): bool {
        $gid = $group->getGID();
        try {
            $grants = $this->grants->forGroups([$gid])[0]['grants'] ?? [];
            if (!in_array('eligible', $grants['_system'] ?? [], true)
                || ($this->life->get($gid)['state'] ?? null) !== GroupLifeMapper::ACTIVE
                || !$this->classifier->trusted($gid)
                || $group->inGroup($user) === false) {
                return false;
            }
            return $this->eligible($user->getUID(), [$gid]) !== true;
        } catch (Throwable) {
            return true;
        }
    }

    /** @return bool|null the resolved eligibility without the given groups; null when unknown */
    private function eligible(string $uid, array $without): ?bool {
        $context = $this->resolver->begin($uid, [], $without, false);
        try {
            return $context->eligible()->allowed;
        } finally {
            $context->close();
        }
    }

    /** Whether the stored cause (U, G) still carries the gen this process recorded for it. */
    private function stillPending(string $uid, string $gid, string $gen): bool {
        $key = IdentityKey::group($gid);
        foreach ($this->rechecks->causes($uid) as $cause) {
            if ($cause['causeKey'] === $key) {
                return $cause['gen'] === $gen;
            }
        }
        return false;
    }

    private static function pair(string $uid, string $gid): string {
        return IdentityKey::user($uid) . "\0" . IdentityKey::group($gid);
    }
}
