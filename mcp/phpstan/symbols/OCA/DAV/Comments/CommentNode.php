<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\DAV\Comments;
/** Analysis-only signature, verified by the pinned NC31–35 platform source fixtures. Never shipped at runtime. */
class CommentNode {
    public function __construct(\OCP\Comments\ICommentsManager $commentsManager,\OCP\Comments\IComment $comment,\OCP\IUserManager $userManager,\OCP\IUserSession $userSession,\Psr\Log\LoggerInterface $logger) {}
    public function updateComment($propertyValue) {}
    public function delete() {}
}
