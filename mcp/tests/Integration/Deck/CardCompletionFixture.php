<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\StackService;
use OCA\Deck\Db\Acl;
use OCA\Deck\NoPermissionException;
use OCP\Server;
use RuntimeException;

/** Native card fixture for testing completion persistence through MCP. */
final class CardCompletionFixture {
    /** @param array<string, mixed> $input @return array<string, int|bool|null> */
    public static function run(string $uid, string $action, array $input): array {
        $boards = Server::get(BoardService::class);
        $cards = Server::get(CardService::class);
        if ($action === 'completion-seed') {
            $board = $boards->create('Completion isolation proof', $uid, '008800');
            $stack = Server::get(StackService::class)->create('Completion', $board->getId(), 0);
            $card = $cards->create('Native completion target', $stack->getId(), 'plain', 0, $uid, 'Preserve this description');
            // The reader has native view access without the edit permission; MCP must preserve that ACL boundary.
            $boards->addAcl($board->getId(), Acl::PERMISSION_TYPE_USER, DeckFixture::READER, false, false, false);
            return ['board' => $board->getId(), 'stack' => $stack->getId(), 'card' => $card->getId()];
        }
        if ($action === 'completion-read') {
            $card = $cards->find((int)$input['card']);
            return ['done' => $card->getDone()?->getTimestamp(), 'title' => $card->getTitle(), 'description' => $card->getDescription()];
        }
        if ($action === 'completion-can-read') {
            try {
                $boards->find((int)$input['board']);
                return ['canRead' => true];
            } catch (NoPermissionException) {
                return ['canRead' => false];
            }
        }
        if ($action === 'completion-cleanup') {
            $boards->delete((int)$input['board']);
            return ['cleaned' => true];
        }
        throw new RuntimeException('Unknown completion fixture action');
    }
}
