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

	public function __construct(private MailAttachmentAccess $attachments, private IncomingFileWriter $files, private PlanState $states, private ?\OCA\Mcp\Mcp\Operations\OperationJournal $journal=null,private ?\OCA\Mcp\Modules\Mail\Access\MailFreshAccess $freshAccess=null) {}

	public function preview(array $arguments, string $userId): array {
		$prepared = $this->prepare($arguments, $userId);
		return $this->plan($prepared, $userId);
	}

	public function save(array $arguments, string $userId): array {
		PlanState::require($arguments);
        $this->freshAccess?->assertActive($userId);
		$prepared = $this->prepare($arguments, $userId);
		$plan = $this->plan($prepared, $userId);
		if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
			throw new PlanChanged($plan + ['warnings' => [['message' => MailMessages::attachmentPlanChanged()]]]);
		}
        if($prepared['source']!==null){$this->attachments->assertSaveSource($userId,$prepared['source']);$this->journal?->checkpoint(['source_tool'=>self::TOOL,'content_source'=>$prepared['source'],'content_sha256'=>$prepared['hash'],'content_revision'=>$prepared['part_revision'],'content_part'=>$prepared['metadata']['attachment_id'],'destination'=>$prepared['path']]);}
        $this->freshAccess?->assertActive($userId);
		$result = $this->files->save($userId, $prepared['path'], $prepared['content'], (bool)($arguments['confirm_shared'] ?? false));
		if (($result['requiresConfirmation'] ?? false) === true) {
			return $result + ['attachment' => $prepared['metadata']];
		}
		return ['attachment' => $prepared['metadata'], 'file' => $result, 'message' => MailMessages::attachmentSaved()];
	}

	private function prepare(array $arguments, string $userId): array {
        $this->files->preview($userId,(string)$arguments['path'],0);
        $attachment=$this->attachments->readForSave($userId,(int)$arguments['message_id'],(string)$arguments['attachment_id']);
        $metadata=['message_id'=>(int)$arguments['message_id'],'attachment_id'=>$attachment['part_id'],'name'=>$attachment['filename'],'mimeType'=>$attachment['mime_type'],'size'=>$attachment['byte_count']];
        return ['path'=>(string)$arguments['path'],'content'=>$attachment['bytes'],'metadata'=>$metadata,'hash'=>$attachment['sha256'],'part_revision'=>hash('sha256',json_encode([$attachment['manifest_revision']??null,$attachment['header_revision']??null,$attachment['wire_sha256']??null],JSON_THROW_ON_ERROR)),'source'=>isset($attachment['source'])?\OCA\Mcp\Modules\Mail\Integration\MailContentReader::stableSource($attachment['source']):null];
	}

	private function plan(array $prepared, string $userId): array {
		$destination = $this->files->preview($userId, $prepared['path'], strlen($prepared['content']));
		$shown = ['source' => $prepared['metadata'], 'content_sha256' => $prepared['hash'], 'destination' => $destination, 'content_source'=>$prepared['source'],'part_revision'=>$prepared['part_revision']];
		return ['action' => 'save_attachment', 'attachment' => $prepared['metadata'], 'content_sha256' => $prepared['hash'],
			'destination' => $destination, 'source'=>$prepared['source'], 'content_revision'=>$prepared['part_revision'], PlanState::ARGUMENT => $this->states->of(self::TOOL, $userId, $shown),
			'message' => MailMessages::attachmentPlan()];
	}
}
