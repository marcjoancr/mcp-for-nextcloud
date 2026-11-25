<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Contracts;

/** Bounded encrypted local drafts, isolated from native Mail drafts and delivery. */
interface MailOwnedDraftStore {
	/** @return array{version:int,drafts:list<array<string,mixed>>} */
	public function load(string $uid, string $actor): array;
	/** Atomically replace the owner snapshot only at the expected storage version. */
	public function compareAndSwap(string $uid, string $actor, int $expectedVersion, array $drafts, int $updatedAt): bool;
}
