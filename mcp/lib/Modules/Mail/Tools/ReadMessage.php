<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Modules\Mail\Handlers\ReadMessageHandler;
use OCA\Mcp\Modules\Mail\Integration\MailBodyReader;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Integration\MailMessageGateway;
use OCA\Mcp\Modules\Mail\Integration\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\Presentation\Link\MailLinks;
use OCA\Mcp\Modules\Mail\Presentation\MailMessageAnswer;
use OCA\Mcp\Modules\Mail\Presentation\MailMessageRows;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;

final class ReadMessage implements Tool {
	public function __construct(
		private MailServices $services,
		private MailGateway $accounts,
		private UserTimezone $timezones,
		private MailLinks $links,
        private ?\OCA\Mcp\Modules\Mail\Integration\MailContentReader $content = null,
	) {
	}

	public static function definition(): ToolDefinition {
		return new ToolDefinition('mail_read_message', 'mail', 'read_message', MailMessages::TOOL_READ_MESSAGE, [
			'type' => 'object', 'required' => ['message_id'], 'additionalProperties' => false,
			'properties' => [
				'message_id' => ['type' => 'integer', 'minimum' => 1],
				'max_chars' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 20000],
			],
		], app: 'mail');
	}

	public function execute(array $arguments, ToolContext $context): array {
		$messages = new MailMessageGateway($this->services);
		$rows = new MailMessageRows($this->timezones->forUser($context->userId), $this->links);
		$answer = new MailMessageAnswer($rows);
		$locator = new MailMessageLocator($this->accounts, $messages);
		return (new ReadMessageHandler($locator, new MailBodyReader($this->services, content: $this->content, sourceTool: 'mail_read_message'), $rows, $answer))
			->handle($arguments, $context->userId);
	}
}
