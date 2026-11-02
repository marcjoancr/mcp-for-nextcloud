<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Minimal Mail-app dependency signatures used by static analysis when its optional app is absent. */
class Horde_Imap_Client_Fetch_Query {
	public function envelope(): void {}
	public function size(): void {}
}

class Horde_Imap_Client_Ids {
	public function __construct(mixed $ids = null) {}
}

class Horde_Imap_Client {
	public const STATUS_UIDVALIDITY = 1;
}
