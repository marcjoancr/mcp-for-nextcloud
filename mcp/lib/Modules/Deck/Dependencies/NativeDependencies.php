<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Dependencies;

use OCA\Mcp\Modules\Deck\Reading\CardReferenceFilter;
use OCA\Mcp\Modules\Deck\Presentation\CapabilityMessages;
use OCA\Mcp\Modules\Deck\Execution\DeckConflictException;
use OCA\Mcp\Modules\Deck\Execution\DeckRefusalException;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;

/** Native dependency reads and writes with the existing readable-cycle boundary. */
final class NativeDependencies implements DependencyAccess {
	/**
 * @param \Closure(): CardMapper $cardMapperProvider
 * @param \Closure(): CardService $cardServiceProvider
 * @param \Closure(): PermissionService $permissionServiceProvider
	 */
	public function __construct(private \OCA\Mcp\Modules\Deck\Access\DeckCaller $caller,
        private \Closure $cardMapperProvider,
        private \Closure $cardServiceProvider,
        private \Closure $permissionServiceProvider,
        private CardReferenceFilter $references,
        private \OCP\IUserManager $users) {
	}

	/**
	 * Reads one native outgoing adjacency after fresh source and target READ checks using the common boundary.
	 *
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Source card, including archived cards still readable in Deck.
	 * @param array|null $view Parent listings shared only within one public graph/state operation.
	 * @param \Closure(array{card: Card, boardId: int}): void|null $acceptTarget Incremental readable graph budget check.
	 * @return array{card: Card, boardId: int, targets: list<array{card: Card, boardId: int}>} Only readable targets, ordered by ID.
	 * @throws \Throwable For an unreadable source or uncertain lookup; never returns a partial accessible adjacency.
	 */
	public function dependencyNode(string $userId, int $cardId, ?array &$view = null, ?\Closure $acceptTarget = null): array {
		if (!property_exists(Card::class, 'dependentCards') || !method_exists(CardMapper::class, 'findDependenciesForCards')) {
			throw new DeckRefusalException(CapabilityMessages::dependenciesUnavailable());
		}
		$view ??= [];
		$source = $this->references->target($userId, $cardId, $view);
		if ($source === null) {
			throw new NoPermissionException('Permission denied');
		}
		$targets = [];
		$card = clone $source['card'];
		$adjacency = ($this->cardMapperProvider)()->findDependenciesForCards([$cardId]);
		$this->references->setNativeDependencies($card, $adjacency[$cardId] ?? []);
		$card = $this->references->filter($userId, $card, $view, true, $targets, $acceptTarget);
		usort($targets, static fn (array $a, array $b): int => $a['card']->getId() <=> $b['card']->getId());
		return ['card' => $card, 'boardId' => $source['boardId'], 'targets' => $targets];
	}

	/**
	 * Fresh editable origin, readable active target and the bounded readable cycle-check graph.
	 *
	 * @param string $userId Authenticated caller.
	 * @param int $originId Origin whose outgoing relation changes.
	 * @param int $targetId Target requiring READ even for removal.
	 * @param bool $assign True for addition.
	 * @param bool $checkCycle False only for read-back after an attempted write.
	 * @return array<string, mixed> Only readable state; never hidden IDs, counts or timestamps.
	 * @throws \Throwable On denial, uncertainty, self-relation or detectable cycle.
	 */
	public function dependencyChangeState(string $userId, int $originId, int $targetId, bool $assign,
		bool $checkCycle = true): array {
		if ($originId === $targetId) {
			throw new DeckRefusalException(CapabilityMessages::dependencyCycle());
		}
		$view = [];
		$originNodes = [$originId => true];
		$originEdges = 0;
		$origin = $this->dependencyNode($userId, $originId, $view,
			static function (array $target) use (&$originNodes, &$originEdges): void {
				$originNodes[(int)$target['card']->getId()] = true;
				if (++$originEdges > DependencyGraph::MAX_EDGES || count($originNodes) > DependencyGraph::MAX_NODES) {
					throw new DeckRefusalException(CapabilityMessages::dependencyGraphLimit());
				}
			});
		$this->caller->bind($userId);
		($this->permissionServiceProvider)()->checkPermission(($this->cardMapperProvider)(), $originId, Acl::PERMISSION_EDIT);
		$target = $this->references->target($userId, $targetId, $view);
		if ($target === null) {
			throw new NoPermissionException('Permission denied');
		}
		foreach ([$origin, $target] as $node) {
			if ($node['card']->getArchived() || $view['boards'][$node['boardId']]->getArchived()) {
				throw new DeckRefusalException(CapabilityMessages::dependencyInactive());
			}
		}
		$present = in_array($targetId, array_map(
			static fn (array $node): int => (int)$node['card']->getId(), $origin['targets']), true);
		$graph = null;
		if ($assign && !$present && $checkCycle) {
			// Reserve the proposed readable edge and distinct target before approving a write.
			if ($originEdges + 1 > DependencyGraph::MAX_EDGES || count($originNodes) + 1 > DependencyGraph::MAX_NODES) {
				throw new DeckRefusalException(CapabilityMessages::dependencyGraphLimit());
			}
			$graph = (new DependencyGraph($this))->read($userId, $targetId, $view);
			foreach ($graph['nodes'] as $node) {
				if ((int)$node['card']->getId() === $originId) {
					throw new DeckRefusalException(CapabilityMessages::dependencyCycle());
				}
			}
		}
		$board = $view['boards'][$origin['boardId']];
		$owner = (string)$board->getOwner();
		return ['origin' => $origin, 'target' => $target, 'present' => $present, 'graph' => $graph,
			'board' => ['owner' => $owner, 'ownerDisplayName' => ($this->users->get($owner)?->getDisplayName() ?: $owner), 'name' => (string)$board->getTitle()]];
	}

	/**
	 * Revalidates the complete readable snapshot immediately before one native association write.
	 *
	 * @param string $userId Authenticated caller.
	 * @param int $originId Editable origin.
	 * @param int $targetId Readable target.
	 * @param bool $assign Add when true; otherwise remove only this relation.
	 * @param array<string, mixed> $expected Canonical snapshot shown in the plan.
	 * @return void
	 * @throws DeckConflictException Before writing when the readable snapshot changed.
	 * @throws \Throwable On refusal or native failure; persistence requires read-back.
	 */
	public function changeDependency(string $userId, int $originId, int $targetId, bool $assign, array $expected): void {
		$state = $this->dependencyChangeState($userId, $originId, $targetId, $assign);
		if (DependencyChange::snapshot($state) !== $expected) {
			throw new DeckConflictException(CapabilityMessages::dependencyPlanChanged());
		}
		if ($state['present'] === $assign) {
			return;
		}
		$cards = ($this->cardServiceProvider)();
		if ($assign) {
			$cards->assignDependentCard($originId, $targetId);
		} else {
			$cards->removeDependentCard($originId, $targetId);
		}
	}
}
