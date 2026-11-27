<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Contracts;

interface MailOwnedDraftSender {
	public function available(): bool;

	/** @return array{name:string,email:string} & array<string,mixed> Private configuration to bind to confirmation. */
	public function accountIdentity(string $uid, object $account): array;

	/** @param array<string,mixed> $draft
	 *  @return 'accepted'|'accepted_copy_unconfirmed'|'uncertain'
	 */
	public function send(string $uid, object $account, array $draft, \Closure $authorize): string;
}
