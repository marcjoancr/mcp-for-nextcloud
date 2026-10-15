<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCA\Mcp\Service\Policy\PolicyWriter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IDBConnection;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Revokes OAuth credentials as soon as Nextcloud disables or deletes the account: a cut at the user's issue_seq
 * (contracts §3.1, cut point (i)), never an unqualified delete. It never throws into the core and never runs SQL
 * inside a caller's transaction (contracts §3.3.6); when it cannot cut, the credentials still fail on their next use,
 * because validation checks the account and observes the revocation itself.
 */
class UserRevocationListener implements IEventListener {
    public function __construct(private PolicyWriter $writer, private IDBConnection $db, private LoggerInterface $logger) {}

    public function handle(Event $event): void {
        if (!($event instanceof UserDeletedEvent
            || ($event instanceof UserChangedEvent && $event->getFeature() === 'enabled'
                && in_array($event->getValue(), [false, 0, '0', 'false'], true)))) {
            return;
        }
        try {
            if ($this->db->inTransaction()) {
                $this->logger->info('Account revocation left to the next use: the event came inside a transaction', ['app' => 'mcp']);
                return;
            }
            $this->writer->revokeCredentials($event->getUser()->getUID());
        } catch (Throwable $e) {
            $this->logger->warning('Cannot revoke the OAuth credentials of a disabled or deleted account', ['app' => 'mcp', 'exception' => $e]);
        }
    }
}
