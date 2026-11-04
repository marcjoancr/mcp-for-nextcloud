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

final class ReadMessageContext implements Tool
{
    public const NAME = 'talk_read_message_context';

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            module: 'talk',
            operation: 'read',
            description: ToolMessages::TOOL_READ_MESSAGE_CONTEXT,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'conversation_token' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 30],
                    'message_id' => ['type' => 'integer', 'minimum' => 1],
                    'before_limit' => ['type' => 'integer', 'minimum' => 0, 'maximum' => ConversationReader::MAX_CONTEXT_MESSAGES_PER_SIDE, 'default' => ConversationReader::DEFAULT_CONTEXT_MESSAGES],
                    'after_limit' => ['type' => 'integer', 'minimum' => 0, 'maximum' => ConversationReader::MAX_CONTEXT_MESSAGES_PER_SIDE, 'default' => ConversationReader::DEFAULT_CONTEXT_MESSAGES],
                ],
                'required' => ['conversation_token', 'message_id'],
                'additionalProperties' => false,
            ],
            app: 'spreed',
        );
    }

    public function __construct(private \Closure $read, private ResultBoundary $results) {}

    public function execute(array $arguments, ToolContext $context): array
    {
        return $this->results->run(
            self::NAME,
            fn(): array => ($this->read)(
                $context->userId,
                (string) ($arguments['conversation_token'] ?? throw new \InvalidArgumentException(ConversationMessages::invalidToken())),
                (int) ($arguments['message_id'] ?? throw new \InvalidArgumentException('message_id must be a positive integer')),
                (int) ($arguments['before_limit'] ?? ConversationReader::DEFAULT_CONTEXT_MESSAGES),
                (int) ($arguments['after_limit'] ?? ConversationReader::DEFAULT_CONTEXT_MESSAGES),
            ),
        );
    }
}
