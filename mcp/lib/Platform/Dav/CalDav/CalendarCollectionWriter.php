<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Platform\Dav\CalDav;

use OCA\Mcp\Platform\Dav\Transport\DavResult;

/** Narrow native CalDAV boundary for creating and sharing actor-owned collections. */
interface CalendarCollectionWriter
{
    public function mkcalendar(string $userId, string $calendarUri, string $displayName): DavResult;
    public function shareCalendar(string $userId, string $calendarUri, string $shareeId, string $displayName, ?bool $readOnly): DavResult;
}
