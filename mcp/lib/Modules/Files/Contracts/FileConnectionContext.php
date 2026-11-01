<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Contracts;

/** Identity and sharing permissions of the current authenticated MCP request. */
interface FileConnectionContext {
    public function current(): int;
    public function allowsVariant(string $tool, string $operation): bool;
}
