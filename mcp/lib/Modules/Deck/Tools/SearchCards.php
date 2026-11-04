<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Deck\Cards\CardInput;
use OCA\Mcp\Modules\Deck\Execution\ResultBoundary;
use OCA\Mcp\Modules\Deck\Presentation\DeckFormatter;
use OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory;
use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;
use OCA\Mcp\Modules\Deck\Presentation\ToolMessages;
use OCA\Deck\Db\Card;

/** Read-only search over visible Deck boards. */
final class SearchCards implements Tool {
    public const TOOL = 'deck_search_cards';
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;
    public const MAX_OFFSET = 10000;
    public const MAX_LABEL_IDS = 100;

    /** @param \Closure(string, array, int, int): array $query Native search capability bound by the composition root. */
    public function __construct(
        private \Closure $query,
        private ResultBoundary $results,
        private DeckFormatterFactory $formatters,
    ) {
    }

    public static function definition(): ToolDefinition {
        $d = [
            'name' => self::TOOL,
            'description' => ToolMessages::TOOL_SEARCH_CARDS_DESCRIPTION,
            'inputSchema' => DeckSchema::schema([
                'text' => DeckSchema::string(ToolMessages::PARAM_SEARCH_TEXT, 1, 200),
                'boardId' => DeckSchema::integer(ToolMessages::PARAM_BOARD_ID, 1),
                'assignee' => DeckSchema::string(ToolMessages::PARAM_ASSIGNEE, 1, 255),
                'labelIds' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_LABEL_IDS,
                    'uniqueItems' => true,
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'description' => ToolMessages::PARAM_LABEL_IDS,
                ],
                'labelMatch' => [
                    'type' => 'string',
                    'enum' => ['any', 'all'],
                    'default' => 'any',
                    'description' => ToolMessages::PARAM_LABEL_MATCH,
                ],
                'dueAfter' => ['type' => 'string', 'description' => ToolMessages::PARAM_DUE_AFTER],
                'dueBefore' => ['type' => 'string', 'description' => ToolMessages::PARAM_DUE_BEFORE],
                'limit' => DeckSchema::integer(ToolMessages::PARAM_LIMIT, 1, self::MAX_LIMIT, self::DEFAULT_LIMIT),
                'offset' => DeckSchema::integer(ToolMessages::PARAM_SEARCH_OFFSET, 0, self::MAX_OFFSET, 0),
            ], []),
            'module' => 'deck',
            'operation' => 'search',
            'app' => 'deck',
        ];
        return new ToolDefinition($d['name'], $d['module'], $d['operation'], $d['description'], $d['inputSchema'], $d['app']);
    }

    /**
     * @param array<string, mixed> $arguments Optional search criteria, result page and board.
     * @param ToolContext $context Authenticated caller.
     * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
     */
    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $formatter = $this->formatters->forUser($userId);
        return $this->results->run(
            self::TOOL,
            'deck_read_card',
            function () use ($arguments, $userId, $formatter): array {
                $labels = $this->labelIds($arguments['labelIds'] ?? []);
                $labelMatch = (string) ($arguments['labelMatch'] ?? 'any');
                if (!in_array($labelMatch, ['any', 'all'], true)) {
                    throw new InvalidArgumentException('Invalid search argument');
                }
                $text = isset($arguments['text']) ? trim((string) $arguments['text']) : '';
                if (mb_strlen($text) > 200) {
                    throw new InvalidArgumentException('Invalid search argument');
                }
                $filters = [
                    'text' => $text,
                    'labelIds' => $labels,
                    'labelMatch' => $labelMatch,
                    'dueAfter' => CardInput::dueBefore($arguments['dueAfter'] ?? null),
                    'dueBefore' => CardInput::dueBefore($arguments['dueBefore'] ?? null),
                ];
                if (isset($arguments['boardId'])) {
                    $boardId = (int) $arguments['boardId'];
                    if ($boardId < 1) {
                        throw new InvalidArgumentException('Invalid search argument');
                    }
                    $filters['boardId'] = $boardId;
                }
                if (isset($arguments['assignee'])) {
                    $assignee = trim((string) $arguments['assignee']);
                    if ($assignee === '' || mb_strlen($assignee) > 255) {
                        throw new InvalidArgumentException('Invalid search argument');
                    }
                    $filters['assignee'] = $assignee;
                }
                $limit = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT)));
                $offset = max(0, min(self::MAX_OFFSET, (int) ($arguments['offset'] ?? 0)));
                $page = ($this->query)($userId, $filters, $limit, $offset);
                $cards = array_values(array_filter(
                    $page['items'],
                    static fn(array $item): bool => $item['card'] instanceof Card && $formatter->isListed($item['card']),
                ));
                return [
                    'cards' => array_map(static fn(array $item): array => $formatter->card($item['card'], (int) $item['boardId']), $cards),
                    'limit' => $limit,
                    'offset' => $offset,
                    'scanned' => (int) ($page['scanned'] ?? 0),
                    'truncated' => (bool) ($page['truncated'] ?? false),
                ];
            },
        );
    }

    /** @return list<int> */
    private function labelIds(mixed $value): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_LABEL_IDS) {
            throw new InvalidArgumentException('Invalid search argument');
        }
        foreach ($value as $id) {
            if (!is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Invalid search argument');
            }
        }
        return array_values(array_unique($value));
    }
}
