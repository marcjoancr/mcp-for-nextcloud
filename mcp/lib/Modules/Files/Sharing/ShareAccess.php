<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Sharing;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\ISharedStorage;
use OCP\IConfig;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * The access rules every sharing tool shares: which node may be shared, which shares the user sees, which of
 * them the user may remove and which grant each share type needs.
 *
 * The actor can manage shares they initiated. Internal shares require ownership. Public links also support
 * readable nodes the actor can re-share under the native ACL and the server's re-sharing policy.
 *
 * Everything goes through IShareManager, so the administrator's sharing rules always apply. Stateless: the
 * user is a parameter of every call and one instance serves every request.
 */
final class ShareAccess {
    /** Shares per page of {@see self::listMine()}. */
    public const PAGE_SIZE = 50;
    /** Highest offset {@see self::listMine()} accepts: each page reads up to offset + PAGE_SIZE + 1 shares per type. */
    public const MAX_OFFSET = 1000;
    /** Share types listed, in the order a listing presents them. */
    public const LISTED_TYPES = [IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_LINK, IShare::TYPE_ROOM];
    /** Grant operation of `files` each manageable type needs. */
    private const OPERATIONS = [IShare::TYPE_USER => 'share', IShare::TYPE_GROUP => 'share', IShare::TYPE_LINK => 'link'];

    public function __construct(
        private IShareManager $shareManager,
        private GrantPolicy $policy,
        // Required on purpose (invariant 1): without it a hidden node would be listed and shareable.
        private FilesAccessGuard $visibilityGuard,
        private IConfig $config,
        private ?\OCA\Mcp\Modules\Files\Contracts\FileConnectionContext $connection = null,
    ) {}

    /**
     * The node at a path, when the user may share it: it exists, is visible, is readable, is not the user
     * folder itself and is owned by the user or eligible for a delegated public link.
     *
     * @param Folder $userFolder the user's folder
     * @param string $uid authenticated user
     * @param string $path user-relative path
     * @return Node the node to share
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws ToolFailure not found (also for a hidden node), forbidden, the root folder, or a node of another owner
     */
    public function nodeForPath(Folder $userFolder, string $uid, string $path, bool $allowPublicReshare = false): Node {
        if (PathGuard::normalize($path) === '/') {
            throw new ToolFailure(SharingMessages::shareRootRefused());
        }
        $node = NodeAccess::run(fn (): Node => NodeAccess::get($userFolder, $path, $this->visibilityGuard));
        if ($node->getOwner()?->getUID() !== $uid) {
            if (!$allowPublicReshare) {
                throw new ToolFailure(SharingMessages::shareNotOwner());
            }
            if (!$this->canReshare($node)) {
                throw new ToolFailure(SharingMessages::shareNodeNotShareable());
            }
        }
        return $node;
    }

    /**
     * Every share the user created for one node, in {@see self::LISTED_TYPES} order.
     *
     * @param string $uid authenticated user, the initiator of the shares returned
     * @param Node $node node already checked by {@see self::nodeForPath()}
     * @return list<IShare> the shares, none of them created by somebody else
     */
    public function sharesOf(string $uid, Node $node): array {
        $shares = [];
        foreach (self::LISTED_TYPES as $type) {
            foreach ($this->sharesBy($uid, $type, $node, -1, 0) as $share) {
                if ($this->canList($uid, $node, $share)) {
                    $shares[] = $share;
                }
            }
        }
        return $shares;
    }

    /**
     * The share the user already has for this node and recipient, which a new share should update instead of
     * duplicating (decision 6). Any link of the user counts for `link`.
     *
     * @param string $uid authenticated user
     * @param Node $node node already checked by {@see self::nodeForPath()}
     * @param ShareRecipient $recipient who the share would be for
     * @return IShare|null the existing share, null when there is none
     */
    public function findExisting(string $uid, Node $node, ShareRecipient $recipient): ?IShare {
        foreach ($this->sharesBy($uid, $recipient->shareType(), $node, -1, 0) as $share) {
            if ($share->getSharedBy() === $uid && $recipient->matches($share)) {
                return $share;
            }
        }
        return null;
    }

    /**
     * One page of every share the user created, all types concatenated in {@see self::LISTED_TYPES} order.
     *
     * Hidden, inaccessible and ineligible shares are skipped before paging. Each type is read from its start
     * up to what the page needs. MAX_OFFSET bounds that cost.
     *
     * @param Folder $userFolder the user's folder, for the user's own view of each node
     * @param string $uid authenticated user, the initiator of every share returned
     * @param int $offset listed shares to skip, 0 to MAX_OFFSET (the tool schema enforces it)
     * @param int $limit shares on the page, at most PAGE_SIZE
     * @return array{items: list<array{share: IShare, node: Node}>, hasMore: bool} the page, each share with its node as the user sees it
     */
    public function listMine(Folder $userFolder, string $uid, int $offset, int $limit = self::PAGE_SIZE): array {
        $offset = max(0, min($offset, self::MAX_OFFSET));
        $limit = max(1, min($limit, self::PAGE_SIZE));
        $need = $offset + $limit + 1;
        $found = [];
        foreach (self::LISTED_TYPES as $type) {
            for ($from = 0; count($found) < $need; $from += $need) {
                $batch = $this->sharesBy($uid, $type, null, $need, $from);
                foreach ($batch as $share) {
                    $node = $this->nodeOf($userFolder, $share);
                    if ($node !== null && $this->canList($uid, $node, $share) && count($found) < $need) {
                        $found[] = ['share' => $share, 'node' => $node];
                    }
                }
                if (count($batch) < $need) {
                    break;
                }
            }
        }
        return ['items' => array_slice($found, $offset, $limit), 'hasMore' => count($found) > $offset + $limit];
    }

    private function canReshare(Node $node): bool {
        if (!$node->isReadable() || !$node->isShareable()
            || $this->config->getAppValue('core', 'shareapi_allow_resharing', 'yes') !== 'yes') {
            return false;
        }
        // The native Files controller inherits download restrictions before calling IManager.
        // These MCP links cannot express that restriction. Require a known, unrestricted incoming share.
        $storage = $node->getStorage();
        if (!$storage instanceof ISharedStorage && method_exists($storage, 'getInstanceOfStorage')) {
            $storage = $storage->getInstanceOfStorage(ISharedStorage::class);
        }
        if (!$storage instanceof ISharedStorage) {
            return false;
        }
        $parent = $storage->getShare();
        return !$parent->getHideDownload() && $parent->getAttributes()?->getAttribute('permissions', 'download') !== false;
    }

    /** Only reveal a delegated link when its bearer would receive at most the actor's current access. */
    public function canList(string $uid, Node $node, IShare $share): bool {
        if ($share->getSharedBy() !== $uid) {
            return false;
        }
        if ($node->getOwner()?->getUID() === $uid) {
            return true;
        }
        return $share->getShareType() === IShare::TYPE_LINK && $this->canReshare($node)
            && ((int)$share->getPermissions() & ~(int)$node->getPermissions()) === 0;
    }

    /**
     * The shared node as the user sees it in their own folder, so a path never reveals the owner's tree.
     *
     * @param Folder $userFolder the user's folder
     * @param IShare $share share of the user
     * @return Node|null the node, null when it is gone, out of the user's folder or hidden by the FilesAccessGuard
     */
    public function nodeOf(Folder $userFolder, IShare $share): ?Node {
        try {
            $node = $userFolder->getFirstNodeById($share->getNodeId());
        } catch (NotFoundException) {
            return null;
        }
        if ($node === null || !$this->visibilityGuard->isVisible($node)) {
            return null;
        }
        return $node;
    }

    /**
     * Whether the user may remove this share (decision 3): created by the user, of an owned file or a delegated public link, of a
     * type this app manages. A Talk attachment (TYPE_ROOM) never is: removing it would erase it from the chat
     * of everybody else, so it is removed in Talk. The owner is the one the share records, which the core
     * keeps in step with ownership transfers.
     *
     * @param IShare $share share listed for the user
     * @param string $uid authenticated user
     * @return bool true when files_unshare may remove it
     */
    public function isRemovable(IShare $share, string $uid): bool {
        return isset(self::OPERATIONS[$share->getShareType()])
            && $share->getSharedBy() === $uid
            && ($share->getShareType() === IShare::TYPE_LINK || $share->getShareOwner() === $uid);
    }

    /**
     * The fields of a share a plan shows and a confirmed write depends on, for the {@see \OCA\Mcp\Mcp\Confirmation\PlanState} of
     * files_share and files_unshare. Whether a password exists, never the stored hash.
     *
     * @param IShare|null $share the share as read now, null when there is none
     * @return array{id:string, bits:int, expires:string|null, noExpiration:bool, note:string, hasPassword:bool}|null null for no share
     */
    public static function snapshot(?IShare $share): ?array {
        if ($share === null) {
            return null;
        }
        return [
            'id' => (string)$share->getFullId(),
            'bits' => (int)$share->getPermissions(),
            'expires' => $share->getExpirationDate()?->format(\DateTimeInterface::ATOM),
            'noExpiration' => $share->getNoExpirationDate(),
            'note' => (string)$share->getNote(),
            'hasPassword' => self::hasPassword($share),
        ];
    }

    /** @return bool whether the share is protected by a password; the stored value is a hash and is never read further */
    public static function hasPassword(IShare $share): bool {
        $password = $share->getPassword();
        return $password !== null && $password !== '';
    }

    /** An upload-only public link has CREATE and none of READ, UPDATE or DELETE. */
    public static function isUploadOnlyLink(IShare $share): bool {
        $bits = (int)$share->getPermissions();
        return $share->getShareType() === IShare::TYPE_LINK
            && ($bits & \OCP\Constants::PERMISSION_CREATE) !== 0
            && ($bits & (\OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_UPDATE | \OCP\Constants::PERMISSION_DELETE)) === 0;
    }

    /** Select link grant based on its actual permission bits, so one grant cannot revoke the other link mode. */
    public function assertShareGrant(string $uid, IShare $share, string $tool): void {
        if (self::isUploadOnlyLink($share)) {
            $this->assertUploadLinkGranted($uid, $tool);
            return;
        }
        $this->assertGranted($uid, $share->getShareType(), $tool);
    }

    /**
     * @param int $shareType IShare::TYPE_* of the share to create, change or remove
     * @return string the `files` grant operation it needs: `share` for USER and GROUP, `link` for LINK
     * @throws ToolFailure for any other type, a Talk attachment included
     */
    public static function operationFor(int $shareType): string {
        return self::OPERATIONS[$shareType] ?? throw new ToolFailure(SharingMessages::shareTypeUnsupported());
    }

    /**
     * Refuses a share type the administrator did not grant to this user. The tool grant only lets the call
     * in; this is the per-type check that has to run on the plan and again on the confirmed call.
     *
     * @param string $uid authenticated user
     * @param int $shareType IShare::TYPE_* about to be created, changed or removed
     * @throws ToolFailure when the grant is missing or the type is not manageable
     */
    public function assertGranted(string $uid, int $shareType, string $tool = 'files_share'): void {
        $operation = self::operationFor($shareType);
        if (!$this->policy->granted($uid, 'files', $operation)
            || !$this->policy->toolGranted($uid, $tool, 'files', $operation)
            || ($this->connection !== null && !$this->connection->allowsVariant($tool, $operation))) {
            throw new ToolFailure(SharingMessages::shareNotGranted());
        }
    }

    /** Require the separate upload-only-link grant at preview and execution time. */
    public function assertUploadLinkGranted(string $uid, string $tool = 'files_create_upload_link'): void {
        // The dedicated tool is admitted by the registry's tool selection. Only
        // multi-action tools such as unshare have separately stored variants.
        if (!$this->policy->granted($uid, 'files', 'upload_link')
            || !$this->policy->toolGranted($uid, $tool, 'files', 'upload_link')
            || ($tool !== 'files_create_upload_link' && $this->connection !== null && !$this->connection->allowsVariant($tool, 'upload_link'))) {
            throw new ToolFailure(SharingMessages::shareNotGranted());
        }
    }

    /**
     * The shares the user initiated, of one type. A missing room provider (Talk disabled) means no room
     * shares rather than an error; every other type always has its provider in the core.
     *
     * @param string $uid initiator
     * @param int $type IShare::TYPE_*
     * @param Node|null $node node to restrict to, null for every node
     * @param int $limit maximum shares, -1 for all
     * @param int $offset shares to skip
     * @return list<IShare>
     */
    private function sharesBy(string $uid, int $type, ?Node $node, int $limit, int $offset): array {
        try {
            return array_values($this->shareManager->getSharesBy($uid, $type, $node, false, $limit, $offset));
        } catch (\Exception $e) {
            if ($type === IShare::TYPE_ROOM) {
                return [];
            }
            throw $e;
        }
    }
    public function assertFolderExposure(\OCP\Files\Node $node): void {
        \OCA\Mcp\Modules\Files\Access\FolderExposureGate::assertExposure($node, $this->visibilityGuard);
    }

    public function hideFolderUrl(\OCP\Files\Node $node): bool {
        return NodeAccess::isFolder($node)
            && \OCA\Mcp\Modules\Files\Access\FolderExposureGate::active($this->visibilityGuard);
    }
}
