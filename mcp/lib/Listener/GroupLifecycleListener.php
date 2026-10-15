<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Listener;

use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Service\Policy\CauseProcessor;
use OCA\Mcp\Service\Policy\DecimalCounter;
use OCA\Mcp\Service\Policy\GroupLifecycle;
use OCA\Mcp\Service\Policy\GroupSourceClassifier;
use OCA\Mcp\Service\Policy\PolicyResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\BeforeGroupCreatedEvent;
use OCP\Group\Events\BeforeGroupDeletedEvent;
use OCP\Group\Events\GroupCreatedEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Observes the group events of contracts §3.3.2 and §3.3.5 for the GID lifecycle, with the same rules as the user
 * events: it never throws into the core, never takes the policy mutex in a pre-event (nor flushes deferred work there)
 * and never refuses or delays a native operation; inside a caller's transaction it runs no SQL at all, and the fact is
 * reconciled later as needs_confirm, never as a confirmed deletion (§3.3.6).
 *
 * - BeforeGroupDeletedEvent(G), when G has a lifecycle: first the causes (U, G) of the credential holders who are in G
 *   and would not be eligible without it (a new random gen per cause), then pending_delete with a new gen, written
 *   without the mutex (GroupLifeMapper::markPendingDelete() explains why that is safe).
 * - GroupDeletedEvent(G): purges G's rows and lifecycle under the mutex only if it still carries the gen the Before
 *   wrote (a policy saved since keeps its new gen), then the protocol for each cause the Before recorded. When the
 *   Before could not record its gen, the lifecycle goes to needs_confirm instead of being left active.
 * - BeforeGroupCreatedEvent(name): reads the revision R_c (one local SELECT, no mutex).
 * - GroupCreatedEvent(G), when G has a lifecycle: with R_c captured for this exact id, purges a policy saved at or
 *   before R_c and keeps one saved after; without it (a backend that names the group itself, a failed read, a Before
 *   inside a transaction), needs_confirm.
 *
 * Creating another group while one is being deleted is never refused: the handlers only observe.
 */
class GroupLifecycleListener implements IEventListener {
    /**
     * group_key => what the Before of a deletion in this process left: the gen it wrote (string), false when G had a
     * lifecycle but the gen could not be written, null when G had none; and the causes it recorded.
     *
     * @var array<string, array{gid:string, gen:string|false|null, causes:list<array{uid:string, gen:string}>}>
     */
    private array $deleting = [];
    /** @var array<string, array{name:string, revision:?string}> group_key => R_c captured by a creation's Before */
    private array $creating = [];

    public function __construct(
        private GroupLifecycle $lifecycle,
        private CauseProcessor $causes,
        private GroupLifeMapper $life,
        private GroupGrantsMapper $grants,
        private RecheckMapper $rechecks,
        private PolicyStateMapper $state,
        private OAuthStore $store,
        private AuthUsersMapper $authUsers,
        private GroupSourceClassifier $classifier,
        private PolicyResolver $resolver,
        private IUserManager $users,
        private IDBConnection $db,
        private ITimeFactory $time,
        private LoggerInterface $logger,
    ) {}

    public function handle(Event $event): void {
        try {
            if ($event instanceof BeforeGroupDeletedEvent) {
                $this->beforeDelete($event->getGroup());
            } elseif ($event instanceof GroupDeletedEvent) {
                $this->afterDelete($event->getGroup());
            } elseif ($event instanceof BeforeGroupCreatedEvent) {
                $this->beforeCreate($event->getName());
            } elseif ($event instanceof GroupCreatedEvent) {
                $this->afterCreate($event->getGroup());
            }
        } catch (Throwable $e) {
            try {
                $this->logger->warning('Group lifecycle signal left to the reconciler', ['app' => 'mcp', 'exception' => $e]);
            } catch (Throwable) {
            }
        }
    }

    private function beforeDelete(IGroup $group): void {
        $gid = $group->getGID();
        $key = IdentityKey::group($gid);
        if ($this->db->inTransaction()) {
            unset($this->deleting[$key]);
            $this->causes->deferGroup($gid);
            return;
        }
        // No flush here: it may take the mutex.
        $this->deleting[$key] = ['gid' => $gid, 'gen' => false, 'causes' => []];
        $row = $this->life->get($gid);
        if ($row === null) {
            $this->deleting[$key]['gen'] = null;
            return;
        }
        // Causes first: once pending_delete is written, G no longer grants and "eligible without G" tells nothing.
        // A failure while collecting them never stops the transition: pending_delete must be written regardless.
        if ($row['state'] === GroupLifeMapper::ACTIVE && $this->grantsEligibility($gid)) {
            try {
                $holders = $this->holders($group);
            } catch (Throwable $e) {
                $holders = [];
                $this->logger->warning('Credential holders of a deleted group not listed; their causes are left to observed use',
                    ['app' => 'mcp', 'target' => $gid, 'exception' => $e]);
            }
            foreach ($holders as $uid) {
                try {
                    $gen = bin2hex(random_bytes(16));
                    $this->rechecks->record($uid, RecheckMapper::REASON_GROUP, $gid, $gen, $this->time->getTime());
                    $this->deleting[$key]['causes'][] = ['uid' => $uid, 'gen' => $gen];
                } catch (Throwable $e) {
                    $this->logger->warning('Recheck cause of a group deletion not recorded', ['app' => 'mcp', 'target' => $uid, 'exception' => $e]);
                }
            }
        }
        $gen = bin2hex(random_bytes(16));
        if ($this->life->markPendingDelete($gid, $row['gen'], $gen)) {
            $this->deleting[$key]['gen'] = $gen;
        }
    }

    private function afterDelete(IGroup $group): void {
        $gid = $group->getGID();
        $key = IdentityKey::group($gid);
        $captured = $this->deleting[$key] ?? null;
        unset($this->deleting[$key]);
        if ($this->db->inTransaction()) {
            // Commit or rollback cannot be known: needs_confirm later, never a purge.
            $this->causes->deferGroup($gid);
            foreach ($captured['causes'] ?? [] as $cause) {
                $this->causes->after($cause['uid'], $key, $cause['gen']);
            }
            return;
        }
        $this->causes->flush();
        if ($captured !== null && $captured['gen'] === null) {
            // G had no lifecycle at its Before: nothing to purge.
            return;
        }
        if ($captured === null || $captured['gen'] === false) {
            // The Before could not record its gen (or never ran here): suspend, never purge on an unknown gen.
            $this->lifecycle->needsConfirm($gid);
        } else {
            $this->lifecycle->deleted($gid, $captured['gen']);
        }
        // After the purge, so "eligible without G" is read with G gone; one failing cause stays for the reconciler.
        foreach ($captured['causes'] ?? [] as $cause) {
            try {
                $this->causes->after($cause['uid'], $key, $cause['gen']);
            } catch (Throwable $e) {
                $this->logger->warning('Recheck cause left to the reconciler', ['app' => 'mcp', 'target' => $cause['uid'], 'exception' => $e]);
            }
        }
    }

    private function beforeCreate(string $name): void {
        $key = IdentityKey::group($name);
        $this->creating[$key] = ['name' => $name, 'revision' => null];
        if ($this->db->inTransaction()) {
            return;
        }
        // One local SELECT; no mutex, no flush.
        $this->creating[$key]['revision'] = $this->state->state()['revision'];
    }

    private function afterCreate(IGroup $group): void {
        $gid = $group->getGID();
        $key = IdentityKey::group($gid);
        $captured = $this->creating[$key] ?? null;
        unset($this->creating[$key]);
        if ($this->db->inTransaction()) {
            $this->causes->deferGroup($gid);
            return;
        }
        $this->causes->flush();
        // R_c counts only for the id its Before announced, byte for byte; a backend that names the group differently
        // leaves no usable R_c, and the lifecycle goes to needs_confirm.
        $revision = $captured !== null && $captured['name'] === $gid ? $captured['revision'] : null;
        $this->lifecycle->created($gid, $revision);
    }

    /** Whether G's stored policy grants eligibility; a failed read counts as yes (the causes are then recorded). */
    private function grantsEligibility(string $gid): bool {
        try {
            return in_array('eligible', $this->grants->forGroups([$gid])[0]['grants']['_system'] ?? [], true)
                && $this->classifier->trusted($gid);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Credential holders in G who may be eligible now and would not be without G (contracts §3.3.2): the ones whose
     * credential a deletion of G must re-check. Cheapest filters first, so the resolutions run only for the few that
     * matter: owners of a live code or grant (not expired, current epoch), intersected with G's member list (one call
     * per serving backend), whose highest generation is above their cut; then the account and two resolutions. A
     * failed member list keeps every live holder; a failed read for one holder records its cause anyway; an account
     * already gone is the account cause's business.
     *
     * @return list<string> their uids
     * @throws \Throwable when the live holders cannot be listed (the caller logs it and still writes pending_delete)
     */
    private function holders(IGroup $group): array {
        $gid = $group->getGID();
        $live = $this->store->liveHolders($this->time->getTime(), $this->state->state()['auth_epoch']);
        if ($live === []) {
            return [];
        }
        try {
            $live = array_intersect_key($live, $this->classifier->members($gid));
        } catch (Throwable) {
            // Unknown membership: the resolutions below decide; over-report rather than omit.
        }
        $relevant = [];
        foreach ($live as ['uid' => $uid, 'gen' => $highest]) {
            try {
                if (self::cutBelow($highest, $this->authUsers->generations($uid)['revoked_below'])) {
                    continue;
                }
                if ($this->users->get($uid) === null) {
                    continue;
                }
                if ($this->eligible($uid, []) !== false && $this->eligible($uid, [$gid]) !== true) {
                    $relevant[] = $uid;
                }
            } catch (Throwable) {
                $relevant[] = $uid;
            }
        }
        return $relevant;
    }

    /** Whether every credential of the holder is already cut (its highest generation is at or below revoked_below). */
    private static function cutBelow(string $highest, string $revokedBelow): bool {
        try {
            return DecimalCounter::compare(DecimalCounter::validate($highest, DecimalCounter::MAX), $revokedBelow) <= 0;
        } catch (\InvalidArgumentException) {
            return false;
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
}
