<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Deck;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Deck\Access\SharedBoardConfirmation;
use OCA\Mcp\Modules\Deck\Execution\WriteMessages;
use Psr\Log\LoggerInterface;

final class CommentOperations {
	public function __construct(private CommentAccess $access, private LoggerInterface $logger, private PlanState $plans) {}

	public function preview(array $arguments, string $userId): array {
		$cardId = (int)$arguments['cardId'];
		$message = (string)$arguments['message'];
		$state = $this->access->commentContext($userId, $cardId);
		return $this->plan($state, $userId, $cardId, $message);
	}

	public function handle(array $arguments, string $userId): array {
		$cardId = (int)$arguments['cardId'];
		$message = (string)$arguments['message'];
		$state = $this->access->commentContext($userId, $cardId);
		$plan = $this->plan($state, $userId, $cardId, $message);
		if (($arguments['confirm'] ?? false) !== true) { return ['requiresConfirmation'=>true] + $plan; }
		PlanState::require($arguments);
		if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
			throw new PlanChanged($plan + ['stalePlan' => true, 'message' => 'Card comments or board access changed; review this updated plan.']);
		}
		$shared = SharedBoardConfirmation::confirmShared($arguments, $userId, $state['board']);
		if ($shared === null && $state['board']['shared'] && ($arguments['confirm_shared'] ?? false) !== true) {
			$shared = [
				'requiresConfirmation' => true,
				'scope' => 'shared',
				'owner' => $state['board']['owner'],
				'ownerDisplayName' => $state['board']['ownerDisplayName'],
				'resource' => $state['board']['name'],
				'message' => WriteMessages::sharedCommentNotice(),
			];
		}
		if ($shared !== null) { return $shared + $plan; }
		try {
			$comment = $this->access->createComment($userId, $cardId, $message);
			if (($comment['actorId'] ?? null) !== $userId || ($comment['cardId'] ?? null) !== $cardId || ($comment['message'] ?? null) !== $message) {
				return ['confirmed'=>false, 'writeObserved'=>false, 'uncertain'=>true, 'message'=>'Deck returned a result that could not be verified. Check the card before trying again.'];
			}
			return ['confirmed'=>true, 'comment'=>$comment, 'note'=>'A concurrent change may occur between the final check and the native append.'];
		} catch (\Throwable $error) {
			$this->logger->warning('Deck comment append returned an error; no retry was attempted');
			try {
				$latest = $this->access->commentContext($userId, $cardId);
				$rows = $this->access->listComments($userId, $cardId, 200, 0);
				$new = array_values(array_filter($rows, static fn(array $row): bool => !in_array($row['id'] ?? null, $state['commentIds'], true)
					&& ($row['actorId'] ?? null) === $userId && ($row['message'] ?? null) === $message && ($row['cardId'] ?? null) === $cardId));
				if (count($new) === 1 && $latest['recipientHash'] === $state['recipientHash']) {
					return ['confirmed'=>false, 'writeObserved'=>true, 'uncertain'=>true, 'comment'=>$new[0], 'message'=>'A matching comment is visible, but Deck did not confirm the append. Check the card; do not retry automatically.'];
				}
			} catch (\Throwable $readError) {
				$this->logger->warning('Deck comment append could not be verified after an error');
			}
			return ['confirmed'=>false, 'writeObserved'=>false, 'uncertain'=>true, 'message'=>'Deck did not confirm whether the comment was added. Check the card before trying again.'];
		}
	}

	public function list(array $arguments, string $userId): array {
		$id = (int)$arguments['cardId'];
		$limit = (int)($arguments['limit'] ?? 50);
		$offset = (int)($arguments['offset'] ?? 0);
		$rows = $this->access->listComments($userId, $id, $limit + 1, $offset);
		$more = count($rows) > $limit;
		if ($more) {
			array_pop($rows);
		}
		return [
			'cardId' => $id,
			'comments' => $rows,
			'limit' => $limit,
			'offset' => $offset,
			'hasMore' => $more,
			'nextOffset' => $more ? $offset + count($rows) : null,
		];
	}

	private function plan(array $state, string $userId, int $cardId, string $message): array {
		$shown = [
			'cardId' => $cardId,
			'card' => $state['card'],
			'message' => $message,
			'board' => $state['board'],
			'recipientHash' => $state['recipientHash'],
			'commentIds' => $state['commentIds'],
		];
		return [
			'card' => $state['card'],
			'board' => ['name' => $state['board']['name'], 'shared' => $state['board']['shared']],
			'commentText' => $message,
			'audienceNote' => 'Visible to people who can access this board; no audience details are included.',
			PlanState::ARGUMENT => $this->plans->of('deck_add_comment', $userId, $shown),
		];
	}
}
