<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Contracts;

/** Encrypted MCP drafts and their independent one-attempt history. */
interface MailOwnedDraftStore {
	/** @return array{version:int,drafts:list<array<string,mixed>>} */
	public function load(string $uid, string $actor): array;
	/** Replace the expected owner snapshot and atomically archive any draft marked with a send state. */
	public function compareAndSwap(string $uid, string $actor, int $expectedVersion, array $drafts, int $updatedAt): bool;

	/** @return array<string,mixed>|null */
	public function findAttempt(string $uid, string $actor, int $account, string $id): ?array;

	/** Settle an uncertain receipt without changing the immutable message or reopening the attempt. */
	public function completeAttempt(string $uid, string $actor, int $account, string $id, int $revision, string $outcome): bool;
}
