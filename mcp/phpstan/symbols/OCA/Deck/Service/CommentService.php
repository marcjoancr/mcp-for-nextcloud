<?php
/*
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Deck\Service;

use OCA\Deck\Db\CardMapper;
use OCP\AppFramework\Http\DataResponse;
use OCP\Comments\ICommentsManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/** Analysis-only Deck API; list accepts string IDs on 1.15–1.16 and integers from 1.17. */
class CommentService {
    public function __construct(
        ICommentsManager $commentsManager, PermissionService $permissionService, CardMapper $cardMapper,
        IUserManager $userManager, LoggerInterface $logger, ?string $userId,
    ) {}

    public function list(int|string $cardId, int $limit = 20, int $offset = 0): DataResponse {
        throw new \LogicException('Analysis-only declaration');
    }

    public function create(int $cardId, string $message, int|string $replyTo = 0): DataResponse {
        throw new \LogicException('Analysis-only declaration');
    }
}
