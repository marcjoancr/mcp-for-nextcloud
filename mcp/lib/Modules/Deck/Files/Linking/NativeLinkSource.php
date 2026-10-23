<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Deck\Files\Linking;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Modules\Deck\Access\DeckCaller;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;
use OCP\IUserManager;

/** D06 source authorization without BoardService enrichment or unrelated Circle-board enumeration. */
final class NativeLinkSource {
    /**
     * @param \Closure():CardMapper $cards
     * @param \Closure():BoardMapper $boards
     * @param \Closure():StackMapper $stacks
     * @param \Closure():PermissionService $permissions
     * @param \Closure():void $clearNativeSession
     */
    public function __construct(private DeckCaller $caller, private \Closure $cards, private \Closure $boards,
        private \Closure $stacks, private \Closure $permissions, private IUserManager $users, private \Closure $clearNativeSession) {}

    /** @return array{card:\OCA\Deck\Db\Card,boardId:int,board:array} */
    public function context(string $uid, int $cardId, bool $edit): array {
        try {
            ($this->clearNativeSession)();
            $this->caller->bind($uid);
            $cards = ($this->cards)();
            $permissions = ($this->permissions)();
            $permissions->checkPermission($cards, $cardId, Acl::PERMISSION_READ);
            ($this->clearNativeSession)();
            if ($edit) {
                $permissions->checkPermission($cards, $cardId, Acl::PERMISSION_EDIT);
                ($this->clearNativeSession)();
            }
            $card = $cards->find($cardId, false);
            $boardId = $cards->findBoardId($cardId);
            if ($card->getDeletedAt() !== 0 || $boardId === null) { throw new NoPermissionException('Permission denied'); }
            $boards = ($this->boards)();
            $boards->flushCache($boardId, $uid);
            $board = $boards->find($boardId, false, false, false);
            $stackIds = array_map(static fn(Stack $stack):int=>(int)$stack->getId(), ($this->stacks)()->findAll($boardId));
            if ($board->getDeletedAt() !== 0 || !in_array((int)$card->getStackId(), $stackIds, true)) {
                throw new NoPermissionException('Permission denied');
            }
            if ($edit && ($card->getArchived() || $board->getArchived())) {
                throw DeckRefusalException::inactiveFileSource($uid, $cardId, $boardId);
            }
            $owner = (string)$board->getOwner();
            return ['card'=>$card, 'boardId'=>$boardId, 'board'=>['owner'=>$owner,
                'ownerDisplayName'=>$this->users->get($owner)?->getDisplayName() ?: $owner, 'name'=>(string)$board->getTitle()]];
        } finally {
            ($this->clearNativeSession)();
        }
    }
}
