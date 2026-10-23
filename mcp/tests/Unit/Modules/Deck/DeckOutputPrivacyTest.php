<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use OCA\Deck\Db\{Board, Stack};
use OCA\Mcp\Tests\Support\Deck\DeckToolFixtures;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../../Support/Deck/Stubs/deck_stubs.php';

/** Every card-producing path keeps native, unscoped attachment enrichment outside its output. */
final class DeckOutputPrivacyTest extends TestCase {
    use DeckToolFixtures;

    public function testBothCallersReceiveTheSameWhitelistAcrossEveryCardProducer(): void {
        // Native counts may include another user's files, a scoped-out file or a hidden-tag alias.
        // The card boundary cannot establish which references contributed, so none are serialized.
        foreach (['alice', 'bob'] as $userId) {
            foreach ([1, 3] as $unscopedCount) {
                $d = $this->deckDoublesOwnedBy($userId);
                $card = $this->card(['id' => 7, 'attachmentCount' => $unscopedCount]);
                $d->cards->method('findCard')->with($userId, 7)->willReturn($card);
                $d->cards->method('listCards')->willReturn(['items' => [$card], 'boardId' => 4]);
                $d->followup->method('followupCards')->willReturn(['items' => [['card' => $card, 'boardId' => 4]], 'truncated' => false]);
                foreach (['createCard', 'updateCard', 'moveCard', 'deleteCard'] as $method) $d->writer->method($method)->willReturn($card);
                $d->boardCreator->method('createBoard')->willReturn(new Board(['id' => 4, 'title' => 'Board']));
                $d->stackCreator->method('createStack')->willReturn(new Stack(['id' => 10, 'boardId' => 4, 'title' => 'Stack']));
                $module = $this->deckModuleFixture($d);
                $calls = [
                    ['deck_read_card', ['cardId' => 7]], ['deck_list_cards', ['stackId' => 10]],
                    ['deck_followup_cards', []], ['deck_create_card', ['stackId' => 10, 'title' => 'New']],
                    ['deck_edit_card', ['cardId' => 7, 'title' => 'Updated']],
                    ['deck_move_card', ['cardId' => 7, 'stackId' => 11]], ['deck_delete_card', ['cardId' => 7]],
                    ['deck_create_board', ['title' => 'Board', 'stacks' => [['title' => 'Stack', 'cards' => [['title' => 'New']]]]]],
                ];
                foreach ($calls as [$tool, $arguments]) {
                    $result = $module->call($tool, $arguments + ['confirm' => true, 'confirm_shared' => true], $userId);
                    self::assertArrayNotHasKey('isError', $result, $tool);
                    $payload = $this->payload($result);
                    self::assertNotEmpty($payload, $tool);
                    $this->assertSafeReferences($payload);
                }
            }
        }
    }

    private function assertSafeReferences(array $payload): void {
        foreach (['attachmentCount', 'attachments', 'files', 'dependencies'] as $field) self::assertArrayNotHasKey($field, $payload);
        foreach ($payload as $value) if (is_array($value)) $this->assertSafeReferences($value);
    }
}
