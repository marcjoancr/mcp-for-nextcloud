<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Mcp\Operations;
/** The connection was discarded: only a fresh persisted journal read may decide what evidence exists. */
final class LocalCommitUnknown extends \RuntimeException {
    public function __construct(string $message,public readonly bool $connectionDiscarded=true){parent::__construct($message);}
}
