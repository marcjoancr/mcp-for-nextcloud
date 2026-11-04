<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Tools;

use OCA\Mcp\Modules\Deck\Tools\SearchCards;
use OCA\Mcp\Tests\Support\Deck\DeckTestHelpers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';

final class SearchCardsTest extends TestCase {
    use DeckTestHelpers;

    public function testSearchReturnsFormattedCardsAndTheNativeTruncationState(): void {
        $requested = null;
        $query = static function (string $userId, array $filters, int $limit, int $offset) use (&$requested): array {
            $requested = [$userId, $filters, $limit, $offset];
            return [
                'items' => [['card' => new \OCA\Deck\Db\Card([
                    'id' => 41,
                    'stackId' => 40,
                    'title' => 'Renew access review',
                    'description' => 'Check permissions',
                    'owner' => 'alice',
                    'deletedAt' => 0,
                    'archived' => false,
                ]), 'boardId' => 4]],
                'truncated' => true,
                'scanned' => 100,
            ];
        };
        $tool = new SearchCards(
            $query,
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );

        $payload = $this->payload($tool->execute([
            'text' => ' renew ',
            'labelIds' => [3, 8],
            'labelMatch' => 'all',
            'dueAfter' => '2026-03-10',
            'dueBefore' => '2026-03-20',
            'limit' => 10,
            'offset' => 2,
        ], new \OCA\Mcp\Mcp\Tools\ToolContext('alice')));

        self::assertSame(['alice', [
            'text' => 'renew',
            'labelIds' => [3, 8],
            'labelMatch' => 'all',
            'dueAfter' => '2026-03-10',
            'dueBefore' => '2026-03-20',
        ], 10, 2], $requested);
        self::assertSame(41, $payload['cards'][0]['id']);
        self::assertSame(4, $payload['cards'][0]['boardId']);
        self::assertSame(100, $payload['scanned']);
        self::assertTrue($payload['truncated']);
    }

    public function testSearchHasItsOwnReadOnlyOperationAndRejectsInvalidDatesBeforeScanning(): void {
        self::assertSame('search', SearchCards::definition()->operation);
        $scanned = false;
        $tool = new SearchCards(
            static function () use (&$scanned): array { $scanned = true; return ['items' => [], 'truncated' => false, 'scanned' => 0]; },
            new \OCA\Mcp\Modules\Deck\Execution\ResultBoundary($this->createMock(LoggerInterface::class)),
            $this->formatterFactory($this->cardFormatter()),
        );

        try {
            $tool->execute(['dueAfter' => '2026-02-30'], new \OCA\Mcp\Mcp\Tools\ToolContext('alice'));
            self::fail('Invalid date was accepted');
        } catch (\InvalidArgumentException) {
            self::assertFalse($scanned);
        }
    }
}
