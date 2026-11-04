<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Talk\Access\ConversationMessages;
use OCA\Mcp\Modules\Talk\Execution\ResultBoundary;
use OCA\Mcp\Modules\Talk\Presentation\ToolMessages;
use OCA\Mcp\Modules\Talk\Reading\ConversationReader;

final class SearchMessages implements Tool
{
    public const NAME = 'talk_search_messages';

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            module: 'talk',
            operation: 'search',
            description: ToolMessages::TOOL_SEARCH_MESSAGES,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'conversation_token' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 30],
                    'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => ConversationReader::MAX_SEARCH_QUERY_LENGTH],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ConversationReader::MAX_MESSAGES, 'default' => ConversationReader::DEFAULT_MESSAGES],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => ConversationReader::MAX_SEARCH_OFFSET, 'default' => 0],
                ],
                'required' => ['conversation_token', 'query'],
                'additionalProperties' => false,
            ],
            app: 'spreed',
        );
    }

    public function __construct(private \Closure $search, private ResultBoundary $results) {}

    public function execute(array $arguments, ToolContext $context): array
    {
        return $this->results->run(
            self::NAME,
            fn(): array => ($this->search)(
                $context->userId,
                (string) ($arguments['conversation_token'] ?? throw new \InvalidArgumentException(ConversationMessages::invalidToken())),
                (string) ($arguments['query'] ?? throw new \InvalidArgumentException(ConversationMessages::invalidSearchQuery())),
                (int) ($arguments['limit'] ?? ConversationReader::DEFAULT_MESSAGES),
                (int) ($arguments['offset'] ?? 0),
            ),
        );
    }
}
