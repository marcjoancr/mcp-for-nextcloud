<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Listener;

use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\OAuth\ConnectionToolsStore;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserCreatedEvent;

/** Before Nextcloud creates a UID, clear the previous account's grants and connection selections together. */
final class ConnectionToolsCreationListener implements IEventListener {
    public function __construct(private ConnectionToolsStore $selections, private ToolGrantsMapper $toolGrants,
        private PolicyTransaction $transaction, private \OCA\Mcp\Mcp\Operations\DurableActorIdentity $actors) {}

    public function handle(Event $event): void {
        if (!$event instanceof BeforeUserCreatedEvent) {
            return;
        }
        // A failed cleanup must abort native creation before the backend can publish the new account.
        $uid = $event->getUid();
        $this->transaction->runInternal([$uid], static fn () => null, function () use ($uid): bool {
            $this->actors->rotateBeforeCreation($uid);
            $changed = $this->toolGrants->deletePrincipal(ToolGrantsMapper::USER, $uid);
            $this->selections->deleteUserSelections($uid);
            return $changed;
        });
    }
}
