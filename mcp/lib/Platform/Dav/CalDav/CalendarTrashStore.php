<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Platform\Dav\CalDav;

/** Actor-authorized adapter to Nextcloud CalDavBackend's native deleted-object APIs. */
interface CalendarTrashStore
{
    /** @return list<array<string,mixed>> entries visible in this principal's native calendar trash */
    public function deletedForPrincipal(string $principalUri): array;
    /** @return array<string,mixed>|null one trash entry visible to this principal, including calendardata */
    public function deletedByIdForPrincipal(int $objectId, string $principalUri): ?array;
    /** Re-authorize by principal, then restore that native row. */
    public function restoreByIdForPrincipal(int $objectId, string $principalUri): void;
}
