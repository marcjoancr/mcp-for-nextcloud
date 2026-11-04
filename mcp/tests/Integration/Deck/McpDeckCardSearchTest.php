<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture;
use PHPUnit\Framework\Attributes\Group;

#[Group('deck')]
final class McpDeckCardSearchTest extends IntegrationTestCase {
    public function testReadOnlyBoardReadersCanSearchWithoutRevealingForeignOrMissingBoards(): void {
        $uids = [DeckFixture::OWNER, DeckFixture::READER, DeckFixture::OUTSIDER];
        $permissions = ToolPermissionFixture::capture($uids, ['deck_search_cards']);
        try {
            ToolPermissionFixture::assign(DeckFixture::OWNER, ['deck_search_cards' => 'allow']);
            ToolPermissionFixture::assign(DeckFixture::READER, ['deck_search_cards' => 'allow']);
            ToolPermissionFixture::assign(DeckFixture::OUTSIDER, ['deck_search_cards' => 'allow']);

            $fixture = DeckFixture::run(DeckFixture::OWNER, 'seed');
            DeckFixture::run(DeckFixture::OWNER, 'search-label', ['card' => $fixture['a'], 'label' => $fixture['keep']]);
            $arguments = [
                'boardId' => $fixture['board'],
                'text' => 'Design',
                'labelIds' => [$fixture['keep']],
                'labelMatch' => 'all',
                'limit' => 10,
            ];

            foreach ([DeckFixture::OWNER, DeckFixture::READER] as $uid) {
                $out = DeckFixture::run($uid, 'mcp-search-cards', ['arguments' => $arguments]);
                self::assertContains('deck_search_cards', $out['tools']);
                $data = $this->data($out);
                self::assertSame([$fixture['a']], array_column($data['cards'], 'id'));
                self::assertFalse($data['truncated']);
            }

            $outsider = DeckFixture::run(DeckFixture::OUTSIDER, 'mcp-search-cards', ['arguments' => $arguments]);
            self::assertSame([], $this->data($outsider)['cards']);
            $hidden = DeckFixture::run(DeckFixture::READER, 'mcp-search-cards', [
                'arguments' => ['boardId' => $fixture['privateBoard']],
            ]);
            $missing = DeckFixture::run(DeckFixture::READER, 'mcp-search-cards', [
                'arguments' => ['boardId' => 2147483000],
            ]);
            self::assertSame($hidden['result'], $missing['result']);
        } finally {
            ToolPermissionFixture::restore($permissions);
        }
    }

    /** @param array<string, mixed> $out @return array<string, mixed> */
    private function data(array $out): array {
        self::assertFalse($out['result']['isError'] ?? false, json_encode($out));
        return json_decode($out['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }
}
