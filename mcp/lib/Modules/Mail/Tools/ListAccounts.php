<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Tools\{Tool, ToolContext, ToolDefinition};
use OCA\Mcp\Modules\Mail\Accounts\AccountLister;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;

final class ListAccounts implements Tool {
    public function __construct(private AccountLister $operation) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(name: AccountLister::TOOL, module: 'mail', operation: 'read',
            description: MailMessages::TOOL_LIST_ACCOUNTS, inputSchema: [
					'type' => 'object',
					'properties' => new \stdClass(),
					'additionalProperties' => false,
				], app: 'mail');
    }

    public function execute(array $arguments, ToolContext $context): array {
        return $this->operation->execute($arguments, $context->userId);
    }
}
