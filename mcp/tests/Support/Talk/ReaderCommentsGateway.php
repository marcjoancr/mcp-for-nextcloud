<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Talk;

use OCP\Comments\IComment;

/** The Talk comment store surface used for ascending and descending bounded context queries. */
interface ReaderCommentsGateway
{
    /** @return array<IComment> */
    public function getCommentsWithVerbForObjectSinceComment(
        string $objectType,
        string $objectId,
        array $verbs,
        int $lastKnownCommentId,
        string $sortDirection = 'asc',
        int $limit = 30,
        bool $includeLastKnown = false,
        string $topmostParentId = '',
    ): array;
}
