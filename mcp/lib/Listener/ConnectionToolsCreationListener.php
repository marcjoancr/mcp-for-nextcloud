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
use Psr\Log\LoggerInterface;
use Throwable;

/** A reused Nextcloud UID starts with no per-connection tool selection from its previous account. */
final class ConnectionToolsCreationListener implements IEventListener {
    public function __construct(private ConnectionToolsStore $selections, private ToolGrantsMapper $toolGrants,
        private PolicyTransaction $transaction, private LoggerInterface $logger) {}

    public function handle(Event $event): void {
        if (!$event instanceof BeforeUserCreatedEvent) {
            return;
        }
        try {
            $uid = $event->getUid();
            $this->transaction->runInternal([$uid], static fn () => null, function () use ($uid): bool {
                $changed = $this->toolGrants->deletePrincipal(ToolGrantsMapper::USER, $uid);
                $this->selections->deleteUserSelections($uid);
                return $changed;
            });
        } catch (Throwable $e) {
            // A preference cleanup must never abort a native user creation. With no profile row, missing selections
            // deny all tools until the new account explicitly chooses them.
            try {
                $this->logger->warning('Could not clear connection tool selections before account creation', [
                    'app' => 'mcp', 'target' => $event->getUid(), 'exception' => $e,
                ]);
            } catch (Throwable) {
            }
        }
    }
}
