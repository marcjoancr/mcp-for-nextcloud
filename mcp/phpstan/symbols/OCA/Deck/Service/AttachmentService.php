<?php
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Service/AttachmentService.php
// SHA-256: b5a28438eea4536c01756d568937d2354ee9ca00759d39a81bd2fad25a19282c


/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Service;

use OCA\Deck\Activity\ActivityManager;
use OCA\Deck\AppInfo\Application;
use OCA\Deck\BadRequestException;
use OCA\Deck\Cache\AttachmentCacheHelper;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Attachment;
use OCA\Deck\Db\AttachmentMapper;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\ChangeHelper;
use OCA\Deck\InvalidAttachmentType;
use OCA\Deck\NoPermissionException;
use OCA\Deck\NotFoundException;
use OCA\Deck\StatusException;
use OCA\Deck\Validators\AttachmentServiceValidator;
use OCP\AppFramework\Db\IMapperException;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Container\ContainerExceptionInterface;
class AttachmentService
{
    public function __construct(AttachmentMapper $attachmentMapper, CardMapper $cardMapper, IUserManager $userManager, ChangeHelper $changeHelper, PermissionService $permissionService, Application $application, AttachmentCacheHelper $attachmentCacheHelper, $userId, IL10N $l10n, ActivityManager $activityManager, AttachmentServiceValidator $attachmentServiceValidator)
    {
    }
    /**
     * @throws ContainerExceptionInterface
     */
    public function registerAttachmentService(string $type, string $class): void
    {
    }
    /**
     * @throws InvalidAttachmentType
     */
    public function getService(string $type): IAttachmentService
    {
    }
    /**
     * @return Attachment[]
     * @throws \OCA\Deck\NoPermissionException
     * @throws BadRequestException
     */
    public function findAll(int $cardId, bool $withDeleted = false): array
    {
    }
    /**
     * @throws BadRequestException
     * @throws InvalidAttachmentType
     * @throws \OCP\DB\Exception
     */
    public function count(int $cardId): int
    {
    }
    /**
     * @return Attachment|\OCP\AppFramework\Db\Entity
     * @throws NoPermissionException
     * @throws StatusException
     * @throws BadRequestException
     */
    public function create(int $cardId, string $type, string $data)
    {
    }
    /**
     * Display the attachment
     *
     * @throws NoPermissionException
     * @throws NotFoundException
     */
    public function display(int $cardId, int $attachmentId, string $type = 'deck_file'): Response
    {
    }
    /**
     * Update an attachment with custom data
     *
     * @throws BadRequestException
     * @throws NoPermissionException
     */
    public function update(int $cardId, int $attachmentId, string $data, string $type = 'deck_file'): Attachment
    {
    }
    /**
     * Either mark an attachment as deleted for later removal or just remove it depending
     * on the IAttachmentService implementation
     *
     * @throws NoPermissionException
     * @throws NotFoundException
     */
    public function delete(int $cardId, int $attachmentId, string $type = 'deck_file'): Attachment
    {
    }
    public function restore(int $cardId, int $attachmentId, string $type = 'deck_file'): Attachment
    {
    }
}
