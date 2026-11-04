<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;

/** Expected refusal for a server-declared message body fetch exceeding the fixed size budget. */
final class MailBodyTooLargeException extends ToolFailure {
	public function __construct(int $bytes) {
		parent::__construct(MailMessages::bodyTooLarge($bytes, MailBodyPreflight::MAX_MESSAGE_BYTES));
	}
}
