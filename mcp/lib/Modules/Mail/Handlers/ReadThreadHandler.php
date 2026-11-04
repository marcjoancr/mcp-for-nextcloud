<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Handlers;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Integration\MailBodyTooLargeException;
use OCA\Mcp\Modules\Mail\Integration\MailMessageGateway;
use OCA\Mcp\Modules\Mail\Integration\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Presentation\MailMessageAnswer;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;

/** Reads a bounded page of bodies from one of the user's own cached Mail threads. */
final class ReadThreadHandler {
	/** Hard upper bound on body fetches and returned bodies per invocation. */
	public const MAX_MESSAGES_PER_PAGE = 10;

	/** Default number of messages read from a thread page. */
	public const DEFAULT_MESSAGES_PER_PAGE = 5;

	/** Hard upper bound on each body's returned text. MailBodyReader separately caps each fetch at 1 MiB. */
	public const MAX_BODY_CHARS = 8000;

	/** Refuse unusually large cached threads; MailManager returns the whole thread in one metadata query. */
	public const MAX_THREAD_MESSAGES = 500;

	public function __construct(
		private MailMessageLocator $locator,
		private MailMessageGateway $messages,
		private ReadMessageHandler $reader,
		private MailMessageAnswer $answer,
	) {
	}

	/**
	 * Reads one stable newest-first page. Cursors are message database ids exclusive within the seed's thread.
	 *
	 * @param array<string, mixed> $arguments validated message_id, before_message_id, limit and max_chars
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 */
	public function handle(array $arguments, string $userId): array {
		$seedId = (int)$arguments['message_id'];
		$seed = $this->locator->locate($userId, $seedId);
		$account = $seed['account'];
		$rootId = $seed['message']->getThreadRootId();
		$rootId = is_string($rootId) && $rootId !== '' ? $rootId : null;
		$candidates = $rootId === null
			? [$seed['message']]
			: $this->messages->thread($userId, $account, $rootId);
		$byId = [];
		foreach ($candidates as $candidate) {
			if ($rootId !== null && $candidate->getThreadRootId() !== $rootId) {
				continue;
			}
			$byId[(int)$candidate->getId()] = $candidate;
		}
		$byId[$seedId] = $seed['message'];
		if (count($byId) > self::MAX_THREAD_MESSAGES) {
			throw new ToolFailure(MailMessages::threadTooLarge(self::MAX_THREAD_MESSAGES));
		}
		$mailboxIds = array_fill_keys($this->messages->mailboxIds($userId, $account), true);
		$byId = array_filter($byId, static fn (object $message): bool => isset($mailboxIds[(int)$message->getMailboxId()]));
		if (!isset($byId[$seedId])) {
			throw new ToolFailure(MailMessages::messageNotFound());
		}
		$thread = array_values($byId);
		usort($thread, static function (object $left, object $right): int {
			$byDate = (int)$right->getSentAt() <=> (int)$left->getSentAt();
			return $byDate !== 0
				? $byDate
				: (int)$right->getId() <=> (int)$left->getId();
		});

		$beforeId = isset($arguments['before_message_id']) ? (int)$arguments['before_message_id'] : null;
		if ($beforeId !== null) {
			$cursorIndex = null;
			foreach ($thread as $index => $candidate) {
				if ((int)$candidate->getId() === $beforeId) {
					$cursorIndex = $index;
					break;
				}
			}
			if ($cursorIndex === null) {
				throw new ToolFailure(MailMessages::messageNotFound());
			}
			$this->assertSameThread($this->locator->locate($userId, $beforeId), $account, $rootId);
			$thread = array_slice($thread, $cursorIndex + 1);
		}

		$limit = max(1, min((int)($arguments['limit'] ?? self::DEFAULT_MESSAGES_PER_PAGE), self::MAX_MESSAGES_PER_PAGE));
		$maxChars = max(1, min((int)($arguments['max_chars'] ?? self::MAX_BODY_CHARS), self::MAX_BODY_CHARS));
		$metadataPage = array_slice($thread, 0, $limit);
		$located = [];
		foreach ($metadataPage as $candidate) {
			$id = (int)$candidate->getId();
			try {
				$found = $this->locator->locate($userId, $id);
			} catch (ToolFailure) {
				// The cache can change after getThread(); stale candidates are skipped before any body fetch.
				continue;
			}
			if ($this->isSameThread($found, $account, $rootId)) {
				$located[] = $found;
			}
		}
		$hasMore = count($thread) > count($metadataPage);
		if ($located === [] && $hasMore) {
			throw new ToolFailure(MailMessages::messageNotFound());
		}
		$rows = [];
		foreach ($located as $entry) {
			try {
				$rows[] = $this->reader->readRow($userId, $entry, $maxChars);
			} catch (MailBodyTooLargeException) {
				$rows[] = $this->reader->unavailableRow($entry);
			}
		}
		$nextBeforeMessageId = $hasMore && $located !== []
			? (int)$located[array_key_last($located)]['message']->getId()
			: null;

		return $this->answer->thread($seedId, $rows, $hasMore, $nextBeforeMessageId);
	}

	/** @param array{message:object, mailbox:object, account:object} $found */
	private function assertSameThread(array $found, object $account, ?string $rootId): void {
		if (!$this->isSameThread($found, $account, $rootId)) {
			throw new ToolFailure(MailMessages::messageNotFound());
		}
	}

	/** @param array{message:object, mailbox:object, account:object} $found */
	private function isSameThread(array $found, object $account, ?string $rootId): bool {
		return (int)$found['account']->getId() === (int)$account->getId()
			&& ($rootId === null || $found['message']->getThreadRootId() === $rootId);
	}
}
