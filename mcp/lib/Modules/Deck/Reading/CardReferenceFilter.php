<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Reading;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\PermissionService;

/** One reference boundary for every returned Card; no hidden edge, count or handle escapes. */
final class CardReferenceFilter {
    public const FRESH_BOARD_SINCE = -2;
	/**
 * @param \Closure(): CardMapper $cardMapperProvider
 * @param \Closure(): PermissionService $permissionServiceProvider
 * @param \Closure(): StackMapper $stackMapperProvider
	 */
	public function __construct(private \OCA\Mcp\Modules\Deck\Access\DeckCaller $caller,
        private \Closure $cardMapperProvider,
        private \Closure $permissionServiceProvider,
        private \Closure $stackMapperProvider) {
	}

	/**
	 * Single reference boundary for every Card returned by this gateway. Hidden/deleted targets have no marker.
	 * The optional view belongs to this public operation only; no property retains it across calls.
	 *
	 * @param string $userId Authenticated caller.
	 * @param Card $card Source card, whose READ has already been checked.
	 * @param array|null $view Parent listings memoized inside one public call.
	 * @param bool $strict True for graph reads: uncertain DB/DI failures abort instead of claiming completeness.
	 * @param array|null $targets Receives fresh readable target cards and board IDs; never raw hidden IDs.
	 * @param \Closure(array{card: Card, boardId: int}): void|null $acceptTarget Optional readable graph budget check before resolving another target.
	 * @return Card Card with only readable native references, without poisoning a cached native entity.
	 * @throws \Throwable In strict mode for an uncertain lookup.
	 */
	public function filter(string $userId, Card $card, ?array &$view = null,
		bool $strict = false, ?array &$targets = null, ?\Closure $acceptTarget = null): Card {
		if (!property_exists($card, 'dependentCards')) {
			return $card;
		}
		$ids = $card->getDependentCards() ?? [];
		if ($ids === []) {
			return $card;
		}
		$view ??= [];
		$readable = [];
		foreach (array_unique($ids) as $id) {
			try {
				$target = $this->target($userId, (int)$id, $view);
				if ($target !== null) {
					if ($acceptTarget !== null) {
						$acceptTarget($target);
					}
					$readable[] = (int)$id;
					if ($targets !== null) {
						$targets[] = $target;
					}
				}
			} catch (NoPermissionException|\OCP\AppFramework\Db\DoesNotExistException) {
				// A denied/missing/deleted target never becomes an edge, count or handle, for any role.
			} catch (\Throwable $e) {
				if ($strict) {
					throw $e;
				}
				// Existing card readers remain fail closed: no failed destination is serialized.
			}
		}
		$card = clone $card;
		$this->setNativeDependencies($card, array_values(array_unique($readable)));
		return $card;
	}

	/**
	 * Set native adjacency on the caller's already cloned card, after its capability guard succeeded.
	 *
	 * Precondition: the caller has already checked the native dependentCards capability.
	 * This setter deliberately performs no capability guard of its own.
	 *
	 * @param Card $card Detached native entity; this method never clones or changes query order.
	 * @param list<int> $ids Native adjacency or the authorized filtered subset.
	 * @return void
	 */
	public function setNativeDependencies(Card $card, array $ids): void {
		$card->setDependentCards($ids);
	}

	/**
	 * Resolve one native reference only after refreshing the caller and checking READ.
	 *
	 * @param string $userId Authenticated caller; native permissions are refreshed on every target.
	 * @param int $id Candidate card ID from the native adjacency, never an ACL bypass query.
	 * @param array $view Fresh parent listings shared only inside one public operation.
	 * @return array{card: Card, boardId: int}|null A readable card with active parents, or a deleted/missing parent.
	 * @throws \Throwable When READ is denied or lookup fails; the caller distinguishes denial from uncertainty.
	 */
	public function target(string $userId, int $id, array &$view): ?array {
		$boards = $this->caller->bind($userId);
		$mapper = ($this->cardMapperProvider)();
		($this->permissionServiceProvider)()->checkPermission($mapper, $id, Acl::PERMISSION_READ);
		$target = $mapper->find($id, false);
		if ($target->getDeletedAt() !== 0) {
			return null;
		}
		if (!isset($view['boards'])) {
			$view['boards'] = [];
			foreach ($boards->findAll(self::FRESH_BOARD_SINCE, false, true) as $board) {
				if ($board->getDeletedAt() === 0) {
					$view['boards'][(int)$board->getId()] = $board;
				}
			}
		}
		$boardId = $mapper->findBoardId($id);
		if (!isset($view['boards'][$boardId])) {
			return null;
		}
		$view['stacks'][$boardId] ??= array_fill_keys(array_map(
			static fn (Stack $stack): int => (int)$stack->getId(),
			($this->stackMapperProvider)()->findAll($boardId),
		), true);
		return isset($view['stacks'][$boardId][(int)$target->getStackId()])
			? ['card' => $target, 'boardId' => (int)$boardId] : null;
	}
}
