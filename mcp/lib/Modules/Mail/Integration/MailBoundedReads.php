<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCP\IConfig;

/** Bounded, owner-scoped reads from the qualified native Mail metadata mapper. */
final class MailBoundedReads {
	public const MAX_ROWS = 50;
	public const DEFAULT_OUTBOX_LIMIT = 20;

	public function __construct(
		private MailServices $services,
		private MailNativeBoundary $boundary,
		private IConfig $config,
	) {
	}

	/** @param array<string,mixed> $arguments @return array{notice:string,items:list<array<string,mixed>>,next_before_id:?int} */
	public function outbox(string $uid, array $arguments): array {
		$this->onlyKeys($arguments, ['account_id', 'limit', 'before_id']);
		$accountId = array_key_exists('account_id', $arguments) ? $arguments['account_id'] : null;
		$limit = array_key_exists('limit', $arguments) ? $arguments['limit'] : self::DEFAULT_OUTBOX_LIMIT;
		$beforeId = array_key_exists('before_id', $arguments) ? $arguments['before_id'] : null;
		if ((array_key_exists('account_id', $arguments) && (!is_int($accountId) || $accountId < 1))
			|| !is_int($limit) || $limit < 1 || $limit > self::MAX_ROWS
			|| (array_key_exists('before_id', $arguments) && (!is_int($beforeId) || $beforeId < 1))) {
			throw new ToolFailure(MailMessages::invalidBoundedRead());
		}

		$reader = $this->reader($uid);
		$page = $reader->outbox($uid, $accountId, $limit, $beforeId);
		if (!is_array($page) || !is_array($page['items'] ?? null) || !array_is_list($page['items'])) {
			throw new ToolFailure(MailMessages::boundedReadUnavailable());
		}
		$items = [];
		foreach (array_slice($page['items'], 0, $limit) as $row) {
			if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] < 1 || !is_int($row['account_id'] ?? null) || $row['account_id'] < 1
				|| ($accountId !== null && $row['account_id'] !== $accountId)) {
				continue;
			}
			$items[] = $this->fields($row, ['id', 'account_id', 'subject', 'subject_truncated', 'failed', 'send_at', 'updated_at']);
		}
		$cursor = $page['next_before_id'] ?? null;
		if ($cursor !== null && (!is_int($cursor) || $cursor < 1)) {
			throw new ToolFailure(MailMessages::boundedReadUnavailable());
		}
		return ['notice' => MailMessages::boundedMailNotice() . ' ' . MailMessages::outboxDeliveryNotice(), 'items' => $items, 'next_before_id' => $cursor];
	}

	/** @param array<string,mixed> $arguments @return array{notice:string,items:list<array<string,mixed>>} */
	public function messageMetadata(string $uid, array $arguments): array {
		$this->onlyKeys($arguments, ['message_ids']);
		$ids = $arguments['message_ids'] ?? null;
		if (!is_array($ids) || !array_is_list($ids) || count($ids) < 1 || count($ids) > self::MAX_ROWS) {
			throw new ToolFailure(MailMessages::invalidBoundedRead());
		}
		$unique = [];
		foreach ($ids as $id) {
			if (!is_int($id) || $id < 1 || isset($unique[$id])) {
				throw new ToolFailure(MailMessages::invalidBoundedRead());
			}
			$unique[$id] = true;
		}

		$reader = $this->reader($uid);
		$requested = array_fill_keys(array_keys($unique), true);
		$rows = $reader->cachedMessages($uid, array_keys($unique));
		if (!is_array($rows) || !array_is_list($rows)) {
			throw new ToolFailure(MailMessages::boundedReadUnavailable());
		}
		$items = [];
		$seen = [];
		foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
			if (!is_array($row) || !is_int($row['id'] ?? null) || !isset($requested[$row['id']]) || isset($seen[$row['id']])
				|| !is_int($row['account_id'] ?? null) || !is_int($row['mailbox_id'] ?? null)) {
				continue;
			}
			$seen[$row['id']] = true;
			$items[] = $this->fields($row, ['id', 'account_id', 'mailbox_id', 'subject', 'subject_truncated', 'sent_at', 'seen', 'flagged']);
		}
		return ['notice' => MailMessages::boundedMailNotice(), 'items' => $items];
	}

	private function reader(string $uid): object {
		if (!$this->services->isEnabledFor($uid)
			|| !$this->boundary->runtimeMatches($this->config->getSystemValueString('version', ''))
			|| !$this->boundary->boundedMetadataSourceVerified()) {
			throw new ToolFailure(MailMessages::boundedReadUnavailable());
		}
		return $this->services->boundedMetadata($uid);
	}

	/** @param array<string,mixed> $arguments @param list<string> $allowed */
	private function onlyKeys(array $arguments, array $allowed): void {
		if (array_diff(array_keys($arguments), $allowed) !== []) {
			throw new ToolFailure(MailMessages::invalidBoundedRead());
		}
	}

	/** @param array<string,mixed> $row @param list<string> $allowed @return array<string,mixed> */
	private function fields(array $row, array $allowed): array {
		return array_intersect_key($row, array_fill_keys($allowed, true));
	}
}
