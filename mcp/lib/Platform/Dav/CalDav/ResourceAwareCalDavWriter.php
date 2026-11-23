<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Platform\Dav\CalDav;

/** Optional writer operations that enable resource principals for one guarded scheduling request. */
interface ResourceAwareCalDavWriter extends CalDavWriter
{
    public function updateWithResourcePrincipals(
        string $userId,
        string $calendarUri,
        string $objectUri,
        string $etag,
        string $ics,
        bool $scheduling = false,
        ?int $sizeLimit = null,
        array $acceptedStatuses = [204],
    ): \OCA\Mcp\Platform\Dav\Transport\DavResult;

    public function deleteWithResourcePrincipals(
        string $userId,
        string $calendarUri,
        string $objectUri,
        ?string $etag = null,
        bool $scheduling = false,
        array $acceptedStatuses = [204],
    ): \OCA\Mcp\Platform\Dav\Transport\DavResult;
}
