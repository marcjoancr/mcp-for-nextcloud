<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Tools\{Tool, ToolContext, ToolDefinition};
use OCA\Mcp\Modules\Mail\Mailboxes\MailboxLister;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;

final class ListMailboxes implements Tool {
    public function __construct(private MailboxLister $operation) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(name: MailboxLister::TOOL, module: 'mail', operation: 'read',
            description: MailMessages::TOOL_LIST_MAILBOXES, inputSchema: [
					'type' => 'object',
					'properties' => [
						'account_id' => ['type' => 'integer', 'minimum' => 1,
							'description' => 'Id of the account, as mail_list_accounts returns it.'],
						'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => MailboxLister::MAX_LIMIT,
							'default' => MailboxLister::DEFAULT_LIMIT,
							'description' => 'Maximum number of folders returned (default 200, at most 500).'],
						'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0,
							'description' => 'Number of folders to skip, from nextOffset of the previous page (default 0).'],
					],
					'required' => ['account_id'],
					'additionalProperties' => false,
				], app: 'mail');
    }

    public function execute(array $arguments, ToolContext $context): array {
        return $this->operation->execute($arguments, $context->userId);
    }
}
