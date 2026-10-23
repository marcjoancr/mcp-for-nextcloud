<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Files;

use OCA\Mcp\Modules\Deck\Reading\CardReferenceFilter;
use OCA\Mcp\Modules\Deck\Presentation\CapabilityMessages;
use OCA\Mcp\Modules\Deck\Execution\DeckConflictException;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\AttachmentService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Sharing\DeckShareProvider;
use OCP\Files\File;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IShare;

/** Native card-file associations; Files visibility remains the responsibility of CardFiles. */
final class NativeCardFiles implements CardFileAccess {
	/**
 * @param \Closure(): AttachmentService $attachmentServiceProvider
 * @param \Closure(): CardMapper $cardMapperProvider
 * @param \Closure(): DeckShareProvider $deckShareProviderProvider
 * @param \Closure(): PermissionService $permissionServiceProvider
	 */
	public function __construct(private \OCA\Mcp\Modules\Deck\Access\DeckCaller $caller,
        private \Closure $attachmentServiceProvider,
        private \Closure $cardMapperProvider,
        private \Closure $deckShareProviderProvider,
        private \Closure $permissionServiceProvider,
        private CardReferenceFilter $references,
        private \OCP\IUserManager $users) {
	}

	/**
	 * Fresh native parent context for file reads/writes; no native attachment metadata/count is returned.
	 *
	 * An inactive marker binds this native context only after READ and EDIT have both succeeded.
	 *
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Source card.
	 * @param bool $edit Require EDIT and active card/board for a mutation.
	 * @return array{card: Card, boardId: int, board: array} Internal authorized context.
	 * @throws \Throwable On missing/denied card, inactive parents or uncertain lookup.
	 */
	public function cardFileContext(string $userId, int $cardId, bool $edit): array {
		$view = [];
		$source = $this->references->target($userId, $cardId, $view);
		if ($source === null) {
			throw new NoPermissionException('Permission denied');
		}
		$board = $view['boards'][$source['boardId']];
		if ($edit) {
			($this->permissionServiceProvider)()->checkPermission(($this->cardMapperProvider)(), $cardId, Acl::PERMISSION_EDIT);
			if ($source['card']->getArchived() || $board->getArchived()) {
				throw new DeckRefusalException(CapabilityMessages::fileUnavailable());
			}
		}
		$owner = (string)$board->getOwner();
		return $source + ['board' => ['owner' => $owner, 'ownerDisplayName' => ($this->users->get($owner)?->getDisplayName() ?: $owner),
			'name' => (string)$board->getTitle()]];
	}

	/**
	 * Native type-12 file shares after source READ. Internal only: callers MUST filter through Files guards.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Readable source.
	 * @return list<IShare> Native associations; never serialize directly.
	 * @throws \Throwable On denied source or provider failure.
	 */
	public function cardFileShares(string $userId, int $cardId): array {
		$this->cardFileContext($userId, $cardId, false);
		return array_values(($this->deckShareProviderProvider)()->getSharedWithByType($cardId, IShare::TYPE_DECK, -1, 0));
	}

	/**
	 * One native file association after source READ and exact type/card identity checks.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Readable source.
	 * @param int $attachmentId Bare share ID, distinct from fileid.
	 * @return IShare|null Internal association, null only for native ShareNotFound.
	 * @throws \Throwable For wrong-card/type, source denial or uncertain lookup.
	 */
	public function cardFileShare(string $userId, int $cardId, int $attachmentId): ?IShare {
		$this->cardFileContext($userId, $cardId, false);
		try {
			$share = ($this->deckShareProviderProvider)()->getShareById((string)$attachmentId);
		} catch (ShareNotFound) {
			return null;
		}
		if ($share->getShareType() !== IShare::TYPE_DECK || (string)$share->getSharedWith() !== (string)$cardId
			|| (int)$share->getId() !== $attachmentId) {
			throw new NoPermissionException('Permission denied');
		}
		return $share;
	}

	/**
	 * Observes a previously authorized association through the provider's unfiltered original-file query.
	 * This avoids mistaking a trash/inaccessible node (ShareNotFound in getShareById) for a deleted share.
	 * @param string $userId Authenticated caller, source READ still required.
	 * @param int $cardId Source of a previously authorized unlink attempt.
	 * @param int $attachmentId Previously authorized share ID.
	 * @param File $node Previously authorized original node; only its stable ID is used after unlink.
	 * @return bool Whether that exact association remains; no other share data leaves the gateway.
	 * @throws \Throwable On uncertain provider lookup or changed source/card binding.
	 */
	public function cardFileShareExists(string $userId, int $cardId, int $attachmentId, File $node): bool {
		$this->cardFileContext($userId, $cardId, false);
		foreach (($this->deckShareProviderProvider)()->getSharesByPath($node) as $share) {
			if ((int)$share->getId() === $attachmentId) {
				if ((string)$share->getSharedWith() !== (string)$cardId || $share->getShareType() !== IShare::TYPE_DECK) {
					throw new NoPermissionException('Permission denied');
				}
				return true;
			}
		}
		return false;
	}

	/**
	 * Revalidates native source and association identity, then uses AttachmentService::delete(..., 'file').
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Editable active source.
	 * @param int $attachmentId Share ID of the visible file association.
	 * @param array<string, mixed> $expected Expected identity from an authorized Files state.
	 * @return void
	 * @throws DeckConflictException Before the native API when identity changed.
	 * @throws \Throwable On denial or native error, possibly after persistence.
	 */
	public function unlinkCardFile(string $userId, int $cardId, int $attachmentId, array $expected): void {
		$this->cardFileContext($userId, $cardId, true);
		$share = $this->cardFileShare($userId, $cardId, $attachmentId);
		if ($share === null || CardFiles::identity($share) !== $expected) {
			throw new DeckConflictException(CapabilityMessages::filePlanChanged());
		}
		($this->attachmentServiceProvider)()->delete($cardId, $attachmentId, 'file');
	}
}
