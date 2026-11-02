<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Integration;

use OCP\AppFramework\Db\DoesNotExistException;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;

/**
 * Thin read-only boundary to the messages of the Mail app, so the handlers never hold a Mail service and are tested
 * with doubles. Every call is a Mail class resolved by {@see MailServices}; nothing here writes.
 *
 * Calls and what they reach (checked in the Mail releases the contract fixtures pin, 5.0.0 to 5.9.0, and in 5.12.5):
 * - `MessageMapper::findIdsByQuery()` and `findByIds()` read the message cache. The Mail app's own search,
 *   `MailSearch::findMessages()`, is not used: it runs `PreviewEnhancer::process()`, which logs in to the IMAP server
 *   for messages not analysed yet and writes previews into the cache.
 * - `MailManager::getMessage()` and `getMailbox()` read one message or folder of the user's own accounts: both join
 *   the account and filter by its owner, so another user's id reads exactly like one that does not exist.
 * - The body is read by {@see MailBodyReader}, not here.
 */
class MailMessageGateway {
	/** Sort order of the Mail mappers: newest first (IMailSearch::ORDER_NEWEST_FIRST). */
	private const NEWEST_FIRST = 'DESC';

	/**
	 * Receives the resolver of the Mail services.
	 *
	 * @param MailServices $services the Mail classes, resolved by name after the per-user enablement check
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
	) {
	}

	/**
	 * The cached folders of an account, as Mail objects.
	 *
	 * @param string $userId authenticated user UID
	 * @param object $account OCA\Mail\Account the user owns
	 * @return list<object> OCA\Mail\Db\Mailbox objects
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function mailboxes(string $userId, object $account): array {
		return array_values($this->services->mailboxMapper($userId)->findAll($account));
	}

	/**
	 * Ids of the messages of one folder that match a query, newest first, from the cache.
	 *
	 * @param string $userId authenticated user UID
	 * @param object $mailbox OCA\Mail\Db\Mailbox of an account the user owns
	 * @param object $query OCA\Mail\Service\Search\SearchQuery
	 * @param int $limit most ids wanted
	 * @return list<int> message ids
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function messageIds(string $userId, object $mailbox, object $query, int $limit): array {
		return array_map('intval', array_values($this->services->messageMapper($userId)->findIdsByQuery($mailbox, $query, self::NEWEST_FIRST, $limit, null)));
	}

	/**
	 * The cached messages of these ids, with their recipients.
	 *
	 * The Mail mapper does not filter by owner here: callers only pass ids that a query on the user's own folders
	 * returned, and {@see MailMessageScope} drops any message outside those folders again.
	 *
	 * @param string $userId authenticated user UID
	 * @param list<int> $ids message ids
	 * @return list<object> OCA\Mail\Db\Message objects
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function messages(string $userId, array $ids): array {
		return $ids === [] ? [] : array_values($this->services->messageMapper($userId)->findByIds($userId, $ids, self::NEWEST_FIRST));
	}

	/**
	 * One cached message of the user's own accounts.
	 *
	 * @param string $userId authenticated user UID
	 * @param int $messageId message id
	 * @return object|null OCA\Mail\Db\Message, or null when it does not exist or is not the user's
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function message(string $userId, int $messageId): ?object {
		try {
			return $this->services->mailManager($userId)->getMessage($userId, $messageId);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * One cached folder of the user's own accounts.
	 *
	 * @param string $userId authenticated user UID
	 * @param int $mailboxId folder id
	 * @return object|null OCA\Mail\Db\Mailbox, or null when it does not exist or is not the user's
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function mailbox(string $userId, int $mailboxId): ?object {
		$exception = MailServices::CLIENT_EXCEPTION_CLASS;
		try {
			return $this->services->mailManager($userId)->getMailbox($userId, $mailboxId);
		} catch (\Exception $e) {
			if ($e instanceof $exception || $e instanceof DoesNotExistException) {
				return null;
			}
			throw $e;
		}
	}

}
