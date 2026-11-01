<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Contracts;

/** Revalidate the issuer of a short-lived checkout link when the link is used. */
interface CheckoutSelection {
    public function permits(string $uid, int $connectionId, string $toolName): bool;
    public function permitsLegacyCheckout(string $uid, string $toolName): bool;
}
