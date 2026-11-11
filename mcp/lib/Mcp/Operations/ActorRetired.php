<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Mcp\Operations;
/** Definite missing/mismatched durable ownership, distinct from transient storage failure. */
final class ActorRetired extends \OCA\Mcp\Mcp\Tools\Results\ToolFailure {
    public function __construct() { parent::__construct('Operation not found.'); }
}
