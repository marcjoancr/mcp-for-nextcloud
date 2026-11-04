<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck\Reading;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Label;
use OCA\Deck\Db\LabelMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\NoPermissionException;
use OCA\Mcp\Tests\Support\Deck\NativeDeckAdapterFixtures;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../../../Support/Deck/Stubs/deck_stubs.php';

final class DeckServiceCardSearchTest extends TestCase {
    use NativeDeckAdapterFixtures;

    public function testSearchCombinesTextAssigneeAllLabelsAndLocalDueDateRangeAcrossReadableBoards(): void {
        $this->recordSetUserId();
        $this->services[BoardService::class]->method('findAll')->willReturn([$this->boardDouble(4), $this->boardDouble(5)]);
        $this->services[PermissionService::class]->expects(self::exactly(2))->method('getPermissions')->willReturnCallback(
            static fn(int $boardId, string $uid): array => $boardId === 4
                ? [Acl::PERMISSION_READ => true, Acl::PERMISSION_MANAGE => false]
                : [Acl::PERMISSION_READ => false],
        );
        $this->services[StackMapper::class]->expects(self::once())->method('findAll')->with(4)->willReturn([
            new Stack(['id' => 40, 'boardId' => 4]),
        ]);
        $matching = $this->card([
            'id' => 41,
            'stackId' => 40,
            'title' => 'Renew access review',
            'description' => 'Q2 security review',
            'duedate' => new \DateTime('2026-03-11 00:00:00', new \DateTimeZone('UTC')),
        ]);
        $wrongText = $this->card(['id' => 42, 'stackId' => 40, 'title' => 'Renew something else']);
        $this->services[CardMapper::class]->expects(self::once())->method('findAll')
            ->with(40, 100, 0)
            ->willReturn([$matching, $wrongText]);
        $this->services[AssignmentMapper::class]->method('findIn')->with([41, 42])->willReturn([
            new Assignment(['cardId' => 41, 'participant' => 'alice', 'type' => Acl::PERMISSION_TYPE_USER]),
        ]);
        $this->services[LabelMapper::class]->expects(self::once())->method('findAssignedLabelsForCards')
            ->with([41, 42], 5000, 0)
            ->willReturn([new Label(['id' => 3, 'cardId' => 41]), new Label(['id' => 8, 'cardId' => 41])]);

        $page = $this->capabilities->search->searchCards('alice', [
            'text' => 'RENEW',
            'assignee' => 'alice',
            'labelIds' => [3, 8],
            'labelMatch' => 'all',
            'dueAfter' => '2026-03-10',
            'dueBefore' => '2026-03-12',
        ], 25, 0);

        self::assertSame([41], array_map(static fn(array $item): int => (int) $item['card']->getId(), $page['items']), json_encode($page));
        self::assertSame([4], array_column($page['items'], 'boardId'));
        self::assertFalse($page['truncated']);
        self::assertSame(2, $page['scanned']);
        self::assertSame(['setUserId:alice'], $this->record('setUserId'));
    }

    public function testSearchIncludesReadOnlySharedBoardsAndExcludesUnreadableBoardsBeforeReadingStacks(): void {
        $this->services[BoardService::class]->method('findAll')->willReturn([$this->boardDouble(4), $this->boardDouble(5)]);
        $this->services[PermissionService::class]->method('getPermissions')->willReturnCallback(
            static fn(int $boardId): array => $boardId === 4
                ? [Acl::PERMISSION_READ => true, Acl::PERMISSION_MANAGE => false]
                : [Acl::PERMISSION_READ => false],
        );
        $this->services[StackMapper::class]->expects(self::once())->method('findAll')->with(4)->willReturn([]);
        $this->services[CardMapper::class]->expects(self::never())->method('findAll');

        $page = $this->capabilities->search->searchCards('alice', [], 10, 0);

        self::assertSame([], $page['items']);
        self::assertFalse($page['truncated']);
    }

    public function testSearchTreatsForeignBoardIdsLikeMissingBoardsWithoutPermissionLookup(): void {
        $this->services[BoardService::class]->method('findAll')->willReturn([$this->boardDouble(4)]);
        $this->services[PermissionService::class]->expects(self::never())->method('getPermissions');
        $this->services[StackMapper::class]->expects(self::never())->method('findAll');

        $page = $this->capabilities->search->searchCards('alice', ['boardId' => 999], 10, 0);

        self::assertSame([], $page['items']);
        self::assertFalse($page['truncated']);
        self::assertSame(0, $page['scanned']);
    }

    public function testSearchStopsAfterTheResultPageIsProvenIncomplete(): void {
        $this->services[BoardService::class]->method('findAll')->willReturn([$this->boardDouble(4), $this->boardDouble(6)]);
        $this->services[PermissionService::class]->method('getPermissions')->willReturn([Acl::PERMISSION_READ => true]);
        $this->services[StackMapper::class]->method('findAll')->with(4)->willReturn([new Stack(['id' => 40, 'boardId' => 4])]);
        $cards = array_map(fn(int $id): Card => $this->card(['id' => $id, 'stackId' => 40, 'title' => 'match']), range(1, 2000));
        $this->services[CardMapper::class]->expects(self::once())->method('findAll')
            ->willReturnCallback(static fn(int $stackId, int $limit, int $offset): array => array_slice($cards, $offset, $limit));
        $this->services[AssignmentMapper::class]->method('findIn')->willReturn([]);
        $this->services[LabelMapper::class]->method('findAssignedLabelsForCards')->willReturn([]);

        $page = $this->capabilities->search->searchCards('alice', ['text' => 'match'], 5, 0);

        self::assertCount(5, $page['items']);
        self::assertTrue($page['truncated']);
        self::assertSame(100, $page['scanned']);
    }

    public function testSearchMarksTheTwoThousandCardScanLimitWhenNoMatchesAreFound(): void {
        $this->services[BoardService::class]->method('findAll')->willReturn([$this->boardDouble(4)]);
        $this->services[PermissionService::class]->method('getPermissions')->willReturn([Acl::PERMISSION_READ => true]);
        $this->services[StackMapper::class]->method('findAll')->with(4)->willReturn([new Stack(['id' => 40, 'boardId' => 4])]);
        $cards = array_map(fn(int $id): Card => $this->card(['id' => $id, 'stackId' => 40, 'title' => 'other']), range(1, 2000));
        $this->services[CardMapper::class]->expects(self::exactly(20))->method('findAll')
            ->willReturnCallback(static fn(int $stackId, int $limit, int $offset): array => array_slice($cards, $offset, $limit));
        $this->services[AssignmentMapper::class]->method('findIn')->willReturn([]);
        $this->services[LabelMapper::class]->method('findAssignedLabelsForCards')->willReturn([]);

        $page = $this->capabilities->search->searchCards('alice', ['text' => 'missing'], 5, 0);

        self::assertSame([], $page['items']);
        self::assertTrue($page['truncated']);
        self::assertSame(2000, $page['scanned']);
    }
}
