<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Mcp\Operations;
/** Trusted lifetime identity, never supplied by tool arguments. */
interface ActorIdentity {
    public function capture(string $uid): string;
    public function assertCurrent(string $uid, string $original): void;
}
