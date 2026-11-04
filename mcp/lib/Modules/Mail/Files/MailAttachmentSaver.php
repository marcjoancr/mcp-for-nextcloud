<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Files;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Contracts\IncomingFileWriter;
use OCA\Mcp\Modules\Mail\Attachments\MailAttachmentAccess;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;

/** Plans and saves attachment bytes through the Files module's normal visibility and write policy. */
final class MailAttachmentSaver {
	public const TOOL = 'mail_save_attachment';

	public function __construct(private MailAttachmentAccess $attachments, private IncomingFileWriter $files, private PlanState $states) {}

	public function preview(array $arguments, string $userId): array {
		$prepared = $this->prepare($arguments, $userId);
		return $this->plan($prepared, $userId);
	}

	public function save(array $arguments, string $userId): array {
		PlanState::require($arguments);
		$prepared = $this->prepare($arguments, $userId);
		$plan = $this->plan($prepared, $userId);
		if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
			throw new PlanChanged($plan + ['warnings' => [['message' => MailMessages::attachmentPlanChanged()]]]);
		}
		$result = $this->files->save($userId, $prepared['path'], $prepared['content'], (bool)($arguments['confirm_shared'] ?? false));
		if (($result['requiresConfirmation'] ?? false) === true) {
			return $result + ['attachment' => $prepared['metadata']];
		}
		return ['attachment' => $prepared['metadata'], 'file' => $result, 'message' => MailMessages::attachmentSaved()];
	}

	private function prepare(array $arguments, string $userId): array {
		$attachment = $this->attachments->read($userId, (int)$arguments['message_id'], (string)$arguments['attachment_id']);
		$content = base64_decode($attachment['content_base64'], true);
		if (!is_string($content)) {
			throw new ToolFailure(MailMessages::attachmentUnavailable());
		}
		$metadata = ['message_id' => (int)$arguments['message_id'], 'attachment_id' => $attachment['id'], 'name' => $attachment['name'], 'mimeType' => $attachment['mimeType'], 'size' => strlen($content)];
		return ['path' => (string)$arguments['path'], 'content' => $content, 'metadata' => $metadata, 'hash' => hash('sha256', $content)];
	}

	private function plan(array $prepared, string $userId): array {
		$destination = $this->files->preview($userId, $prepared['path'], strlen($prepared['content']));
		$shown = ['source' => $prepared['metadata'], 'content_sha256' => $prepared['hash'], 'destination' => $destination];
		return ['action' => 'save_attachment', 'attachment' => $prepared['metadata'], 'content_sha256' => $prepared['hash'],
			'destination' => $destination, PlanState::ARGUMENT => $this->states->of(self::TOOL, $userId, $shown),
			'message' => MailMessages::attachmentPlan()];
	}
}
