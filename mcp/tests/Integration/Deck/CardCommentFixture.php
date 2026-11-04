<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\AclMapper;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\StackService;
use OCP\Server;
use RuntimeException;

/** Native visible and private cards for testing comment authorization and confirmation. */
final class CardCommentFixture {
	/** @param array<string, mixed> $input @return array<string, int|bool> */
	public static function run(string $uid, string $action, array $input): array {
		$boards = Server::get(BoardService::class);
		$cards = Server::get(CardService::class);
		if ($action === 'comment-seed') {
			$visibleBoard = $boards->create('Comment shared target', $uid, '006699');
			$visibleStack = Server::get(StackService::class)->create('Comments', $visibleBoard->getId(), 0);
			$visibleCard = $cards->create('Comment target', $visibleStack->getId(), 'plain', 0, $uid, 'Original description');
			$boards->addAcl($visibleBoard->getId(), Acl::PERMISSION_TYPE_USER, DeckFixture::READER, false, false, false);
			$privateBoard = $boards->create('Comment private target', $uid, '996600');
			$privateStack = Server::get(StackService::class)->create('Private comments', $privateBoard->getId(), 0);
			$privateCard = $cards->create('Private card', $privateStack->getId(), 'plain', 0, $uid, 'Private description');
			return ['visibleBoard' => $visibleBoard->getId(), 'visibleCard' => $visibleCard->getId(),
				'privateBoard' => $privateBoard->getId(), 'privateCard' => $privateCard->getId()];
		}
		if ($action === 'comment-count') {
			$data = Server::get(\OCA\Deck\Service\CommentService::class)->list((int)$input['card'], 100, 0)->getData();
			return ['count' => is_array($data) ? count($data) : 0];
		}
		if ($action === 'comment-revoke') {
			$visibleBoard = (int)$input['visibleBoard'];
			foreach (Server::get(AclMapper::class)->findAll($visibleBoard) as $acl) {
				if ($acl->getType() === Acl::PERMISSION_TYPE_USER && $acl->getParticipant() === DeckFixture::READER) {
					$boards->deleteAcl((int)$acl->getId());
					return ['revoked' => true];
				}
			}
			return ['revoked' => false];
		}
		if ($action === 'comment-cleanup') {
			$boards->delete((int)$input['visibleBoard']);
			$boards->delete((int)$input['privateBoard']);
			return ['cleaned' => true];
		}
		throw new RuntimeException('Unknown comment fixture action');
	}
}
