<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Accounts;

use OCA\Mcp\Modules\Mail\Contracts\MailReader;
use OCA\Mcp\Modules\Mail\Presentation\MailRows;
use OCA\Mcp\Modules\Mail\Integration\MailUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/**
 * Backs `mail_list_accounts`: the accounts the user owns in the Mail app, identified by id, name and address only.
 */
final class AccountLister {
	/** MCP tool name this handler serves. */
	public const TOOL = 'mail_list_accounts';

	/**
	 * Receives the Mail boundary and the row shaper.
	 *
	 * @param MailReader $gateway read-only access to the accounts of the Mail app
	 * @param MailRows $rows cleans the untrusted names
	 * @return void
	 */
	public function __construct(
		private MailReader $gateway,
		private MailRows $rows,
	) {
	}

	/**
	 * Lists the accounts of the user.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments (none are defined)
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function execute(array $arguments, string $userId): array {
		return ToolResult::json([
			'accounts' => array_map($this->rows->account(...), $this->gateway->accounts($userId)),
		]);
	}
}
