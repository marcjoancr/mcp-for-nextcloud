<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Attachments;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Integration\MailCacheGeneration;
use OCA\Mcp\Modules\Mail\Integration\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\Integration\MailBodyPreflight;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\Presentation\MailRows;

/** Bounded attachment reads against the same cached message generation Mail synchronized. */
class MailAttachmentAccess {
	public const MAX_ATTACHMENT_BYTES = 1048576;
	public const MAX_ATTACHMENTS = 100;

	public function __construct(private MailMessageLocator $locator, private MailServices $services, private MailBodyPreflight $preflight = new MailBodyPreflight()) {}

	/** @return list<array{id:string,name:?string,mimeType:string,size:int}> */
	public function list(string $userId, int $messageId): array {
		return array_map($this->metadata(...), array_slice($this->attachments($userId, $messageId), 0, self::MAX_ATTACHMENTS));
	}

	/** @return array{id:string,name:?string,mimeType:string,size:int,content_base64:string} */
	public function read(string $userId, int $messageId, string $attachmentId): array {
		if (!preg_match('/^[0-9]+(?:\.[0-9]+)*$/D', $attachmentId)) {
			throw new ToolFailure(MailMessages::attachmentNotFound());
		}
		foreach ($this->attachments($userId, $messageId) as $attachment) {
			if ((string)$attachment->getId() !== $attachmentId) {
				continue;
			}
			$content = (string)$attachment->getContent();
			if (strlen($content) > self::MAX_ATTACHMENT_BYTES) {
				throw new ToolFailure(MailMessages::attachmentTooLarge(strlen($content), self::MAX_ATTACHMENT_BYTES));
			}
			return $this->metadata($attachment) + ['content_base64' => base64_encode($content)];
		}
		throw new ToolFailure(MailMessages::attachmentNotFound());
	}

	/** @return list<object> Mail Attachment objects */
	private function attachments(string $userId, int $messageId): array {
		$found = $this->locator->locate($userId, $messageId);
		$mailbox = $found['mailbox'];
		$generation = MailCacheGeneration::of($mailbox);
		if ($generation === null) {
			throw new ToolFailure(MailMessages::messageChanged());
		}
		$client = null;
		try {
			$client = $this->services->clientFactory($userId)->getClient($found['account']);
			$mailboxName = (string)$mailbox->getName();
			if ($this->preflight->uidValidity($client, $mailboxName) !== $generation) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			$this->preflight->check($client, $mailboxName, (int)$found['message']->getUid(), $found['message']->getMessageId() === null ? null : (string)$found['message']->getMessageId());
			if ($this->preflight->uidValidity($client, $mailboxName) !== $generation) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			$rows = $this->services->imapMessageMapper($userId)->getAttachments($client, $mailboxName, (int)$found['message']->getUid(), $userId);
			if ($this->preflight->uidValidity($client, $mailboxName) !== $generation) {
				throw new ToolFailure(MailMessages::messageChanged());
			}
			if (count($rows) > self::MAX_ATTACHMENTS) {
				throw new ToolFailure(MailMessages::tooManyAttachments(self::MAX_ATTACHMENTS));
			}
			foreach ($rows as $attachment) {
				$size = strlen((string)$attachment->getContent());
				if ($size > self::MAX_ATTACHMENT_BYTES) {
					throw new ToolFailure(MailMessages::attachmentTooLarge($size, self::MAX_ATTACHMENT_BYTES));
				}
			}
			return array_values($rows);
		} catch (ToolFailure $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new ToolFailure(MailMessages::attachmentUnavailable(), 0, $e);
		} finally {
			try {
				$client?->logout();
			} catch (\Throwable) {
			}
		}
	}

	private function metadata(object $attachment): array {
		return [
			'id' => MailRows::text((string)$attachment->getId()),
			'name' => $attachment->getName() === null ? null : MailRows::text((string)$attachment->getName()),
			'mimeType' => MailRows::text((string)$attachment->getType()),
			'size' => strlen((string)$attachment->getContent()),
		];
	}
}
