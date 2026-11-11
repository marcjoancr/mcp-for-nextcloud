<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Mail\Handlers\SearchMessagesHandler;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Integration\MailMessageGateway;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\Presentation\Link\MailLinks;
use OCA\Mcp\Modules\Mail\Presentation\MailMessageAnswer;
use OCA\Mcp\Modules\Mail\Presentation\MailMessageRows;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\Search\MailMessageSearch;
use OCA\Mcp\Modules\Mail\Search\MailSearchCriteria;

final class SearchMessages implements Tool {
	public function __construct(
		private MailServices $services,
		private MailGateway $accounts,
		private UserTimezone $timezones,
		private MailLinks $links,
        private MailMessageGateway $messages,
	) {
	}

	public static function definition(): ToolDefinition {
		$texts = static fn (string $field): array => ['type' => 'array', 'maxItems' => MailSearchCriteria::MAX_ADDRESSES,
			'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => MailSearchCriteria::MAX_TEXT],
			'description' => "Fragments of a $field name or address; any fragment may match."];
		return new ToolDefinition('mail_search_messages', 'mail', 'search', MailMessages::TOOL_SEARCH_MESSAGES, [
			'type' => 'object', 'additionalProperties' => false,
			'properties' => [
				'account_id' => ['type' => 'integer', 'minimum' => 1],
				'mailbox_id' => ['type' => 'integer', 'minimum' => 1],
				'from' => $texts('sender'), 'to' => $texts('recipient'), 'cc' => $texts('copy recipient'),
				'subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => MailSearchCriteria::MAX_TEXT],
				'since' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 25],
				'until' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 25],
				'unread' => ['type' => 'boolean'], 'flagged' => ['type' => 'boolean'],
				'has_attachments' => ['type' => 'boolean'],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
				'cursor' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4096],
			],
		], app: 'mail');
	}

	public function execute(array $arguments, ToolContext $context): array {
		$messages = $this->messages;
		$zone = $this->timezones->forUser($context->userId);
		$rows = new MailMessageRows($zone, $this->links);
		$answer = new MailMessageAnswer($rows);
		$search = new MailMessageSearch($this->services, $messages);
		return (new SearchMessagesHandler($this->accounts, $messages, $search, $answer, $zone, time()))
			->handle($arguments, $context->userId);
	}
}
