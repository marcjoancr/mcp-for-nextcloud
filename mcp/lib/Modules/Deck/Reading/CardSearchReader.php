<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Reading;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Label;
use OCA\Deck\Db\LabelMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Modules\Deck\Access\DeckCaller;
use OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess;
use OCA\Mcp\Modules\Deck\Cards\CardDate;
use OCA\Mcp\Platform\Time\UserTimezone;

/** Searches active cards using Deck's caller-scoped board list and native ACL service. */
class CardSearchReader {
    private const MAX_BOARDS = 100;
    private const MAX_STACKS = 500;
    private const MAX_SCAN = 2000;
    private const PAGE_SIZE = 100;
    private const MAX_LABEL_ROWS = 5000;

    /**
     * @param \Closure(): StackMapper $stackMapperProvider
     * @param \Closure(): CardMapper $cardMapperProvider
     * @param \Closure(): LabelMapper $labelMapperProvider
     * @param \Closure(): PermissionService $permissionServiceProvider
     */
    public function __construct(
        private DeckCaller $caller,
        private BoardReader $boards,
        private AssignmentAccess $assignments,
        private \Closure $stackMapperProvider,
        private \Closure $cardMapperProvider,
        private \Closure $labelMapperProvider,
        private \Closure $permissionServiceProvider,
        private ?UserTimezone $zones = null,
    ) {
    }

    /**
     * Searches only active cards on boards the caller can read. Label matching defaults to `any`;
     * `all` requires every requested label ID. Date endpoints are inclusive in the user's timezone.
     *
     * @param string $userId Authenticated caller.
     * @param array{text?: string, boardId?: int, assignee?: string, labelIds?: list<int>, labelMatch?: 'any'|'all', dueAfter?: string, dueBefore?: string} $filters Validated filters.
     * @param int $limit Maximum result count, already clamped by the tool.
     * @param int $offset Number of matching cards to skip.
     * @return array{items: list<array{card: Card, boardId: int}>, truncated: bool, scanned: int}
     */
    public function searchCards(string $userId, array $filters, int $limit, int $offset): array {
        $readable = $this->readableBoards($userId, isset($filters['boardId']) ? (int) $filters['boardId'] : null);
        if ($readable['boards'] === []) {
            return ['items' => [], 'truncated' => $readable['truncated'], 'scanned' => 0];
        }

        /** @var StackMapper $stackMapper */
        $stackMapper = ($this->stackMapperProvider)();
        /** @var CardMapper $cardMapper */
        $cardMapper = ($this->cardMapperProvider)();
        /** @var LabelMapper $labelMapper */
        $labelMapper = ($this->labelMapperProvider)();
        $zone = $this->zones?->forUser($userId) ?? new \DateTimeZone(date_default_timezone_get());
        $items = [];
        $matched = 0;
        $scanned = 0;
        $truncated = $readable['truncated'];
        $stackCount = 0;

        foreach ($readable['boards'] as $board) {
            $boardId = (int) $board->getId();
            $remainingStacks = self::MAX_STACKS - $stackCount;
            if ($remainingStacks <= 0) {
                $truncated = true;
                break;
            }
            $stacks = $stackMapper->findAll($boardId, $remainingStacks + 1, 0);
            if (count($stacks) > $remainingStacks) {
                $truncated = true;
                $stacks = array_slice($stacks, 0, $remainingStacks);
            }
            $stackCount += count($stacks);

            foreach ($stacks as $stack) {
                if (!$stack instanceof Stack) {
                    continue;
                }
                $stackId = (int) $stack->getId();
                $stackOffset = 0;
                while ($scanned < self::MAX_SCAN) {
                    $readCount = min(self::PAGE_SIZE, self::MAX_SCAN - $scanned);
                    $page = $this->cardsOfStack($cardMapper, $stackId, $readCount, $stackOffset);
                    if ($page === []) {
                        break;
                    }
                    $scanned += count($page);
                    $stackOffset += count($page);

                    $this->assignments->attachAssignments($page);
                    $labels = $labelMapper->findAssignedLabelsForCards(
                        array_map(static fn(Card $card): int => (int) $card->getId(), $page),
                        self::MAX_LABEL_ROWS,
                        0,
                    );
                    if (count($labels) >= self::MAX_LABEL_ROWS) {
                        // Label rows were capped; any card could have more labels beyond this page.
                        // Stop without claiming a complete label-filtered result.
                        $truncated = true;
                        break 3;
                    }
                    $labelsByCard = [];
                    foreach ($labels as $label) {
                        if ($label instanceof Label) {
                            $labelsByCard[(int) $label->getCardId()][] = $label;
                        }
                    }
                    foreach ($page as $card) {
                        if (!$this->matches($card, $filters, $labelsByCard[(int) $card->getId()] ?? [], $zone)) {
                            continue;
                        }
                        $matched++;
                        if ($matched <= $offset) {
                            continue;
                        }
                        if (count($items) < $limit) {
                            $items[] = ['card' => $card, 'boardId' => $boardId];
                            continue;
                        }
                        // One additional match proves that another result page exists.
                        $truncated = true;
                        break 4;
                    }

                    if (count($page) < $readCount) {
                        break;
                    }
                    if ($scanned >= self::MAX_SCAN) {
                        $truncated = true;
                        break 3;
                    }
                }
            }
        }

        return ['items' => $items, 'truncated' => $truncated, 'scanned' => $scanned];
    }

    /**
     * Visible boards are first selected through Deck's caller-scoped service. Each is then checked
     * for READ permission explicitly, so read-only shares are included and manage rights are not required.
     * A requested foreign or missing board is absent from the visible list and has the same empty result.
     *
     * @param int|null $boardId Optional requested board.
     * @return array{boards: list<Board>, truncated: bool}
     */
    private function readableBoards(string $userId, ?int $boardId): array {
        $visible = $this->boards->listBoards($userId);
        if ($boardId !== null) {
            $visible = array_values(array_filter($visible, static fn(Board $board): bool => (int) $board->getId() === $boardId));
        }
        $truncated = $boardId === null && count($visible) > self::MAX_BOARDS;
        $visible = array_slice($visible, 0, self::MAX_BOARDS);
        /** @var PermissionService $permissions */
        $permissions = ($this->permissionServiceProvider)();
        $readable = [];
        foreach ($visible as $board) {
            if (($permissions->getPermissions((int) $board->getId(), $userId)[Acl::PERMISSION_READ] ?? false) === true) {
                $readable[] = $board;
            }
        }
        return ['boards' => $readable, 'truncated' => $truncated];
    }

    /**
     * Cards of one stack. Deck's native mapper applies active/deleted filters to this query.
     *
     * @return list<Card>
     */
    private function cardsOfStack(CardMapper $mapper, int $stackId, int $limit, int $offset): array {
        $cards = $mapper->findAll($stackId, $limit, $offset);
        return is_array($cards) ? array_values(array_filter($cards, static fn($card): bool => $card instanceof Card)) : [];
    }

    /** @param list<Label> $labels */
    private function matches(Card $card, array $filters, array $labels, \DateTimeZone $zone): bool {
        $text = mb_strtolower(trim((string) ($filters['text'] ?? '')));
        if ($text !== '' && !str_contains(mb_strtolower((string) $card->getTitle()), $text)
            && !str_contains(mb_strtolower((string) $card->getDescription()), $text)) {
            return false;
        }
        if (isset($filters['assignee']) && !in_array((string) $filters['assignee'], \OCA\Mcp\Modules\Deck\Assignments\AssignedUsers::assignedUids($card), true)) {
            return false;
        }
        $requestedLabels = array_values(array_unique(array_map('intval', $filters['labelIds'] ?? [])));
        if ($requestedLabels !== []) {
            $have = array_map(static fn(Label $label): int => (int) $label->getId(), $labels);
            $matched = array_intersect($requestedLabels, $have);
            if (($filters['labelMatch'] ?? 'any') === 'all' ? count($matched) !== count($requestedLabels) : $matched === []) {
                return false;
            }
        }
        $dueDay = CardDate::dueDay($card, $zone);
        if (isset($filters['dueAfter']) && ($dueDay === null || $dueDay < $filters['dueAfter'])) {
            return false;
        }
        if (isset($filters['dueBefore']) && ($dueDay === null || $dueDay > $filters['dueBefore'])) {
            return false;
        }
        return true;
    }
}
