<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Search;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Integration\MailMessageGateway;
use OCA\Mcp\Modules\Mail\Integration\MailUnavailableException;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\Presentation\MailRows;

/**
 * The folders one message listing or search reads: one folder, every folder of one account, or every folder of every
 * account the user owns, always resolved through the user's own accounts.
 *
 * A folder the cache cannot answer for is never searched and never silently dropped: it is reported with the reason
 * (not selectable, never synchronized by the Mail app, or locked while the Mail app synchronizes it). A search over
 * more folders than the limit is refused with a request to narrow it, because each folder is one cache query.
 */
final class MailMessageScope {
	/** Most folders one search reads from the cache. */
	public const MAX_FOLDERS = 100;


	/**
	 * Holds the resolved folders.
	 *
	 * @param list<array{account:object, mailbox:object, accountId:int, mailboxId:int, path:string, trash:bool}> $folders folders to read
	 * @param list<array{accountId:int, mailboxId:int, path:string, reason:string}> $skipped folders not read, with the reason
	 * @return void
	 */
	private function __construct(
		public readonly array $folders,
		public readonly array $skipped,
	) {
	}

	/**
	 * Resolves the folders of a listing or search.
	 *
	 * @param MailGateway $accounts the accounts of the user
	 * @param MailMessageGateway $messages the folders and messages of the Mail app
	 * @param string $userId authenticated user UID
	 * @param int|null $accountId one account, or null for every account the user owns
	 * @param int|null $mailboxId one folder, or null for every folder of the accounts
	 * @param int $now current Unix time, for the synchronization locks of the Mail app
	 * @return self the folders
	 * @throws ToolFailure when the account or folder is not the user's, when a single folder cannot be read, or when
	 *     there are more folders than the limit
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public static function resolve(MailGateway $accounts, MailMessageGateway $messages, string $userId, ?int $accountId, ?int $mailboxId, int $now): self {
		$candidates = [];
		if ($mailboxId !== null) {
			$mailbox = $messages->mailbox($userId, $mailboxId);
			$account = $mailbox === null ? null : $accounts->ownedAccount($userId, (int)$mailbox->getAccountId());
			if ($mailbox === null || $account === null || ($accountId !== null && (int)$account->getId() !== $accountId)) {
				throw new ToolFailure(MailMessages::mailboxNotFound());
			}
			$candidates[] = [$account, $mailbox];
		} else {
			$owned = [];
			if ($accountId !== null) {
				$owned[] = $accounts->ownedAccount($userId, $accountId) ?? throw new ToolFailure(MailMessages::accountNotFound());
			} else {
				foreach ($accounts->accounts($userId) as $row) {
					$account = $accounts->ownedAccount($userId, $row['id']);
					if ($account !== null) {
						$owned[] = $account;
					}
				}
			}
			foreach ($owned as $account) {
				foreach ($messages->mailboxes($userId, $account) as $mailbox) {
					$candidates[] = [$account, $mailbox];
				}
			}
		}
		$folders = [];
		$skipped = [];
		foreach ($candidates as [$account, $mailbox]) {
			$entry = [
				'accountId' => (int)$account->getId(),
				'mailboxId' => (int)$mailbox->getId(),
				'path' => MailRows::text((string)$mailbox->getName()),
			];
			$reason = self::unreadable($mailbox, $now);
			if ($reason !== null) {
				$skipped[] = $entry + ['reason' => $reason];
				continue;
			}
			$folders[] = $entry + [
				'account' => $account,
				'mailbox' => $mailbox,
				'trash' => MailRows::special((string)$mailbox->getName(), (string)($mailbox->getSpecialUse() ?? '[]')) === 'trash',
			];
		}
		if ($mailboxId !== null && $skipped !== []) {
			throw new ToolFailure(MailMessages::mailboxUnreadable($skipped[0]['reason']));
		}
		$limit = self::MAX_FOLDERS;
		if (count($folders) > $limit) {
			throw new ToolFailure(MailMessages::tooManyFolders(count($folders), $limit));
		}
		return new self($folders, $skipped);
	}

	/**
	 * Ids of the folders read, sorted, for the cursor fingerprint.
	 *
	 * @return list<int> folder ids
	 */
	public function mailboxIds(): array {
		$ids = array_map(static fn (array $folder): int => $folder['mailboxId'], $this->folders);
		sort($ids);
		return $ids;
	}

	/**
	 * Why the cache cannot answer for a folder.
	 *
	 * @param object $mailbox OCA\Mail\Db\Mailbox
	 * @param int $now current Unix time
	 * @return string|null not_selectable, not_synchronized or synchronizing; null when the folder can be read
	 */
	private static function unreadable(object $mailbox, int $now): ?string {
		if ($mailbox->getSelectable() !== null && !$mailbox->getSelectable()) {
			return 'not_selectable';
		}
		if (!$mailbox->isCached()) {
			return 'not_synchronized';
		}
		if ($mailbox->hasLocks($now)) {
			return 'synchronizing';
		}
		return null;
	}
}
