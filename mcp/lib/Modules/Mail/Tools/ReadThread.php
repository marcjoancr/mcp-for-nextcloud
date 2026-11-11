<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Mail\Handlers\ReadMessageHandler;
use OCA\Mcp\Modules\Mail\Handlers\ReadThreadHandler;
use OCA\Mcp\Modules\Mail\Integration\MailBodyReader;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Integration\MailMessageGateway;
use OCA\Mcp\Modules\Mail\Integration\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\Presentation\Link\MailLinks;
use OCA\Mcp\Modules\Mail\Presentation\MailMessageAnswer;
use OCA\Mcp\Modules\Mail\Presentation\MailMessageRows;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Platform\Time\UserTimezone;

final class ReadThread implements Tool {
	public function __construct(
		private MailServices $services,
		private MailGateway $accounts,
		private UserTimezone $timezones,
		private MailLinks $links,
        private ?\OCA\Mcp\Modules\Mail\Integration\MailContentReader $content = null,
	) {
	}

	public static function definition(): ToolDefinition {
		return new ToolDefinition('mail_read_thread', 'mail', 'read_message', MailMessages::TOOL_READ_THREAD, [
			'type' => 'object',
			'required' => ['message_id'],
			'additionalProperties' => false,
			'properties' => [
				'message_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Visible message id used to select its owned account and thread.'],
				'before_message_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Return older messages than this thread message id, exclusive.'],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ReadThreadHandler::MAX_MESSAGES_PER_PAGE, 'default' => ReadThreadHandler::DEFAULT_MESSAGES_PER_PAGE],
				'max_chars' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ReadThreadHandler::MAX_BODY_CHARS, 'default' => ReadThreadHandler::MAX_BODY_CHARS],
			],
		], app: 'mail');
	}

	public function execute(array $arguments, ToolContext $context): array {
		$messages = new MailMessageGateway($this->services);
		$rows = new MailMessageRows($this->timezones->forUser($context->userId), $this->links);
		$answer = new MailMessageAnswer($rows);
		$locator = new MailMessageLocator($this->accounts, $messages);
		$reader = new ReadMessageHandler($locator, new MailBodyReader($this->services, content: $this->content, sourceTool: 'mail_read_thread'), $rows, $answer);
		return (new ReadThreadHandler($locator, $messages, $reader, $answer))->handle($arguments, $context->userId);
	}
}
