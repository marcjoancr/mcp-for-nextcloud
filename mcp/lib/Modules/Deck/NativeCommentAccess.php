<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Deck;

use OCA\Deck\Db\CardMapper;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\CommentService;
use OCA\Mcp\Modules\Deck\Access\DeckCaller;

/** Calls Deck's read-gated CommentService, binding each fresh service to the exact MCP session user. */
final class NativeCommentAccess implements CommentAccess {
	/** @param \Closure():CardService $cards @param \Closure():CardMapper $cardMapper
	 * @param \Closure(string):CommentService $comments @param \Closure(string,int,string):array{hash:string,isShared:bool} $audienceFingerprint
	 * @param \Closure(string,object,int):array $cardReference
	 * @param \Closure(string):string $ownerDisplayName */
	public function __construct(private DeckCaller $caller, private \Closure $cards, private \Closure $cardMapper,
		private \Closure $comments, private \Closure $audienceFingerprint,
		private \Closure $cardReference, private \Closure $ownerDisplayName) {}

	public function commentContext(string $userId, int $cardId): array {
		$target = $this->activeTarget($userId, $cardId);
		$commentRows = $this->nativeList($userId, $cardId, 200, 0);
		$owner = (string)$target['board']->getOwner();
		$audience = ($this->audienceFingerprint)($userId, $target['boardId'], $owner);
		$reference = ($this->cardReference)($userId, $target['card'], $target['boardId']);
		return [
			'card' => $reference,
			'board' => [
				'owner' => $owner,
				'ownerDisplayName' => ($this->ownerDisplayName)($owner),
				'name' => (string)$target['board']->getTitle(),
				'shared' => $audience['isShared'],
			],
			'recipientHash' => $audience['hash'],
			'commentIds' => array_values(array_map(static fn(array $row): int => $row['id'], $commentRows)),
		];
	}

	public function listComments(string $userId, int $cardId, int $limit, int $offset): array {
		$this->activeTarget($userId, $cardId);
		return $this->nativeList($userId, $cardId, min(200, $limit), $offset);
	}

	public function createComment(string $userId, int $cardId, string $message): array {
		$this->activeTarget($userId, $cardId);
		/** @var CommentService $service */ $service=($this->comments)($userId);
		// Do not pass replyTo: signatures differ between supported Deck versions.
		$created = $this->sanitize($this->data($service->create($cardId, $message)), $cardId);
		if ($created['actorId'] !== $userId || $created['cardId'] !== $cardId || $created['message'] !== $message) {
			throw new \UnexpectedValueException('Deck comment result did not match the requested author and target');
		}
		return $created;
	}

	private function nativeList(string $userId,int $cardId,int $limit,int $offset): array {
		$this->caller->bind($userId);
		/** @var CommentService $service */ $service=($this->comments)($userId);
		$method=new \ReflectionMethod($service,'list');
		$type=$method->getParameters()[0]->getType();
		$nativeId = $type instanceof \ReflectionNamedType && $type->getName() === 'string' ? (string)$cardId : $cardId;
		$rows = $this->data($service->list($nativeId, $limit, $offset));
		if (!is_array($rows)) { throw new \UnexpectedValueException('Deck returned invalid comments'); }
		return array_values(array_map(fn(array $row): array => $this->sanitize($row, $cardId), array_filter($rows, 'is_array')));
	}

	/** @return array{card:object,board:object,boardId:int} */
	private function activeTarget(string $userId, int $cardId): array {
		$boards = $this->caller->bind($userId);
		/** @var CardService $cards */
		$cards = ($this->cards)();
		/** @var CardMapper $mapper */
		$mapper = ($this->cardMapper)();
		$card = $cards->find($cardId);
		if ($card->getArchived()) {
			throw new \InvalidArgumentException('Archived cards cannot receive or show comments');
		}
		$boardId = (int)$mapper->findBoardId($cardId);
		$board = $boards->find($boardId, false, false);
		if ($board->getArchived() || $board->getDeletedAt() > 0) {
			throw new \InvalidArgumentException('Archived or deleted boards cannot receive or show comments');
		}
		return ['card' => $card, 'board' => $board, 'boardId' => $boardId];
	}

	private function data(mixed $response): mixed {
		if (is_object($response) && method_exists($response,'getData')) { return $response->getData(); }
		return $response;
	}

	private function sanitize(array $row,int $cardId): array {
		if (!isset($row['id'], $row['objectId'], $row['message'], $row['actorId'], $row['actorType'], $row['creationDateTime'])
			|| !is_numeric($row['id']) || (int)$row['id'] < 1 || !is_numeric($row['objectId']) || (int)$row['objectId'] < 1) {
			throw new \UnexpectedValueException('Deck returned an incomplete comment');
		}
		$objectId = (int)$row['objectId'];
		if ($objectId !== $cardId) {
			throw new \UnexpectedValueException('Deck comment target did not match');
		}
		$actorId = (string)$row['actorId'];
		$actorType = (string)$row['actorType'];
		if ($actorId === '' || $actorType === '') {
			throw new \UnexpectedValueException('Deck returned an invalid comment author');
		}
		$date = (string)$row['creationDateTime'];
		if (\DateTimeImmutable::createFromFormat(\DateTime::ATOM, $date) === false) {
			throw new \UnexpectedValueException('Deck returned an invalid comment timestamp');
		}
		return [
			'id' => (int)$row['id'],
			'cardId' => $objectId,
			'message' => (string)$row['message'],
			'actorId' => $actorId,
			'actorType' => $actorType,
			'actorDisplayName' => (string)($row['actorDisplayName'] ?? $actorId),
			'creationDateTime' => $date,
		];
	}
}
