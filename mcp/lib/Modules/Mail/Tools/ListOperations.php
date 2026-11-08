<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;
use OCA\Mcp\Mcp\Tools\{Tool,ToolContext,ToolDefinition};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Mail\Operations\MailOperationReader;
final class ListOperations implements Tool {
    public function __construct(private MailOperationReader $reader) {}
    public static function definition(): ToolDefinition {
        return new ToolDefinition('mail_list_operations','mail','read_operations',
            'Lists bounded write receipts belonging to this user and connection, filtered by current original-tool permissions. No mail is sent and no operation is repeated.',
            ['type'=>'object','properties'=>['limit'=>['type'=>'integer','minimum'=>1,'maximum'=>50,'default'=>20],
            'offset'=>['type'=>'integer','minimum'=>0,'maximum'=>10000,'default'=>0]],'additionalProperties'=>false],app:'mail');
    }
    public function execute(array $arguments,ToolContext $context): array {
        return ToolResult::json($this->reader->list($context->userId,(int)($arguments['limit']??20),(int)($arguments['offset']??0)));
    }
}
