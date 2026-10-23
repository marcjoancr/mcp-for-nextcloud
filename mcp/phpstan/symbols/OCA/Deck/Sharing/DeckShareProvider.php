<?php
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Sharing/DeckShareProvider.php
// SHA-256: a41695cdbb513201fdfae629d2a4fc37b3c040d901c97666dd41f09277f21269


/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Deck\Sharing;

use OC\Files\Cache\Cache;
use OCA\Deck\Cache\AttachmentCacheHelper;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\User;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Share\Exceptions\GenericShareException;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IPartialShareProvider;
use OCP\Share\IShare;
use OCP\Share\IShareProviderGetUsers;
class DeckShareProvider implements \OCP\Share\IShareProvider, IPartialShareProvider, IShareProviderGetUsers
{
    public const DECK_FOLDER = '/Deck';
    public const DECK_FOLDER_PLACEHOLDER = '/{DECK_PLACEHOLDER}';
    public const SHARE_TYPE_DECK_USER = IShare::TYPE_DECK_USER;
    public function __construct(private IDBConnection $dbConnection, private IManager $shareManager, private BoardMapper $boardMapper, private CardMapper $cardMapper, private PermissionService $permissionService, private AttachmentCacheHelper $attachmentCacheHelper, private IL10N $l, private ITimeFactory $timeFactory, private IMimeTypeLoader $mimeTypeLoader, private ?string $userId = null)
    {
    }
    public static function register(IEventDispatcher $dispatcher): void
    {
    }
    /**
     * @inheritDoc
     */
    public function identifier()
    {
    }
    /**
     * @inheritDoc
     */
    public function create(IShare $share)
    {
    }
    /**
     * @inheritDoc
     */
    public function update(IShare $share)
    {
    }
    /**
     * @inheritDoc
     */
    public function delete(IShare $share)
    {
    }
    /**
     * @inheritDoc
     */
    public function deleteFromSelf(IShare $share, $recipient)
    {
    }
    /**
     * @inheritDoc
     */
    public function restore(IShare $share, string $recipient): IShare
    {
    }
    /**
     * @inheritDoc
     */
    public function move(IShare $share, $recipient)
    {
    }
    /**
     * @inheritDoc
     * @returns
     */
    public function getSharesInFolder($userId, Folder $node, $reshares, $shallow = true)
    {
    }
    /**
     * @inheritDoc
     */
    public function getSharesBy($userId, $shareType, $node, $reshares, $limit, $offset)
    {
    }
    /**
     * @inheritDoc
     * @throws ShareNotFound
     */
    public function getShareById($id, $recipientId = null)
    {
    }
    /**
     * Get shares for a given path
     *
     * @param Node $path
     * @return IShare[]
     */
    public function getSharesByPath(Node $path): array
    {
    }
    /**
     * Get shared with the given user
     *
     * @param string $userId get shares where this user is the recipient
     * @param int $shareType
     * @param Node|null $node
     * @param int $limit The max number of entries returned, -1 for all
     * @param int $offset
     * @return IShare[]
     */
    public function getSharedWith($userId, $shareType, $node, $limit, $offset): array
    {
    }
    public function getSharedWithByPath(string $userId, int $shareType, string $path, bool $forChildren, int $limit, int $offset): iterable
    {
    }
    /**
     * Get shared with the card
     *
     * @param int $cardId
     * @param int $shareType
     * @param int $limit The max number of entries returned, -1 for all
     * @param int $offset
     * @return IShare[]
     */
    public function getSharedWithByType(int $cardId, int $shareType, $limit, $offset): array
    {
    }
    public function isAccessibleResult(array $data): bool
    {
    }
    /**
     * @inheritDoc
     * @psalm-suppress InvalidReturnType Not returning anything
     */
    public function getShareByToken($token)
    {
    }
    /**
     * @inheritDoc
     */
    public function userDeleted($uid, $shareType)
    {
    }
    /**
     * @inheritDoc
     */
    public function groupDeleted($gid)
    {
    }
    /**
     * @inheritDoc
     */
    public function userDeletedFromGroup($uid, $gid)
    {
    }
    /**
     * Get the access list to the array of provided nodes.
     *
     * @see IManager::getAccessList() for sample docs
     *
     * @param Node[] $nodes The list of nodes to get access for
     * @param bool $currentAccess If current access is required (like for removed shares that might get revived later)
     * @return array
     */
    public function getAccessList($nodes, $currentAccess)
    {
    }
    /**
     * For each user the path with the fewest slashes is returned
     * @param array $shares
     * @return array
     */
    protected function filterSharesOfUser(array $shares): array
    {
    }
    /**
     * Get all children of this share
     *
     * Not part of IShareProvider API, but needed by OC\Share20\Manager.
     *
     * @param IShare $parent
     * @return IShare[]
     */
    public function getChildren(IShare $parent): array
    {
    }
    /**
     * @inheritDoc
     */
    public function getAllShares(): iterable
    {
    }
    public function getOrphanedAttachmentShares(): array
    {
    }
    public function getUsersForShare(IShare $share): iterable
    {
    }
}
