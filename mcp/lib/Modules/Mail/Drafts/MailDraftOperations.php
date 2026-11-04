<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Drafts;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\Presentation\MailRows;
use OCP\AppFramework\Db\DoesNotExistException;

/** Owner-scoped access to the Mail app's local, unsent drafts. */
final class MailDraftOperations {
	public const WRITE_TOOL = 'mail_write_draft';
	public const DELETE_TOOL = 'mail_delete_draft';
	public const MAX_DRAFTS = 50;
	public const MAX_BODY_CHARS = 20000;
	public const MAX_RECIPIENTS = 50;
	public const MAX_VISIBLE_RECIPIENTS = 3 * self::MAX_RECIPIENTS;

	public function __construct(private MailServices $services, private MailGateway $accounts, private PlanState $states) {}

	/** @return array<string,mixed> a bounded list or one locally stored draft */
	public function read(array $arguments, string $userId): array {
		$service = $this->services->draftsService($userId);
		if (isset($arguments['draft_id'])) {
			$draft = $this->draft($service, $userId, (int)$arguments['draft_id']);
			$account = $this->accounts->ownedAccount($userId, (int)$draft->getAccountId());
			if ($account === null) {
				throw new ToolFailure(MailMessages::draftNotFound());
			}
			return ['draft' => $this->row($draft, true), 'account' => ['id' => (int)$account->getId(), 'email' => (string)$account->getEmail()]];
		}

		$all = $this->services->localMessageMapper($userId)->getAllForUser($userId, $this->services->localDraftType($userId));
		usort($all, static fn (object $a, object $b): int => ((int)$b->getUpdatedAt() <=> (int)$a->getUpdatedAt()) ?: ((int)$a->getId() <=> (int)$b->getId()));
		$limit = min(self::MAX_DRAFTS, max(1, (int)($arguments['limit'] ?? 20)));
		$offset = max(0, (int)($arguments['offset'] ?? 0));
		$visible = [];
		foreach ($all as $draft) {
			$account = $this->accounts->ownedAccount($userId, (int)$draft->getAccountId());
			if ($account !== null) {
				$visible[] = ['draft' => $draft, 'account' => $account];
			}
		}
		$rows = [];
		foreach (array_slice($visible, $offset, $limit) as $item) {
			$rows[] = $this->row($item['draft'], false) + ['account' => ['id' => (int)$item['account']->getId(), 'email' => (string)$item['account']->getEmail()]];
		}
		$nextOffset = $offset + count($rows);
		return ['drafts' => $rows, 'count' => count($rows), 'hasMore' => count($visible) > $nextOffset,
			'limit' => $limit, 'offset' => $offset, 'next_offset' => count($visible) > $nextOffset ? $nextOffset : null];
	}

	/** The preview of creating or updating one local draft. */
	public function previewWrite(array $arguments, string $userId): array {
		$prepared = $this->prepareWrite($arguments, $userId);
		return [
			'action' => $prepared['draft'] === null ? 'create' : 'update',
			'account' => ['id' => $prepared['accountId'], 'email' => (string)$prepared['account']->getEmail()],
			'draft_id' => $prepared['draft'] === null ? null : (int)$prepared['draft']->getId(),
			'before' => $prepared['draft'] === null ? null : $this->row($prepared['draft'], true),
			'after' => $prepared['fields'],
			PlanState::ARGUMENT => $this->states->of(self::WRITE_TOOL, $userId, $this->writeState($prepared)),
			'message' => MailMessages::draftPlan(),
		];
	}

	/** Writes only a local draft, after the plan still matches current Mail state. */
	public function write(array $arguments, string $userId): array {
		PlanState::require($arguments);
		$prepared = $this->prepareWrite($arguments, $userId);
		if (!PlanState::matches($this->states->of(self::WRITE_TOOL, $userId, $this->writeState($prepared)), $arguments)) {
			$plan = $this->previewWrite($arguments, $userId);
			throw new PlanChanged($plan + ['warnings' => [['message' => MailMessages::draftPlanChanged()]]]);
		}
		$fields = $prepared['fields'];
		$draft = $prepared['draft'] ?? $this->services->newLocalDraft($userId);
		$draft->setType($this->services->localDraftType($userId));
		$draft->setAccountId($prepared['accountId']);
		$draft->setSubject($fields['subject']);
		$draft->setBodyPlain($fields['body_plain']);
		$draft->setBodyHtml($fields['body_html']);
		$draft->setHtml($fields['is_html']);
		$draft->setEditorBody($fields['is_html'] ? $fields['body_html'] : $fields['body_plain']);
		$draft->setUpdatedAt(time());
		$service = $this->services->draftsService($userId);
		$saved = $prepared['draft'] === null
			? $service->saveMessage($prepared['account'], $draft, $fields['to'], $fields['cc'], $fields['bcc'], [])
			: $service->updateMessage($prepared['account'], $draft, $fields['to'], $fields['cc'], $fields['bcc'], []);
		return ['draft' => $this->row($saved, true), 'message' => MailMessages::draftSaved()];
	}

	public function previewDelete(array $arguments, string $userId): array {
		$prepared = $this->prepareDelete($arguments, $userId);
		return [
			'action' => 'delete', 'draft' => $this->row($prepared['draft'], true),
			PlanState::ARGUMENT => $this->states->of(self::DELETE_TOOL, $userId, $this->deleteState($prepared)),
			'message' => MailMessages::draftDeletePlan(),
		];
	}

	public function delete(array $arguments, string $userId): array {
		PlanState::require($arguments);
		$prepared = $this->prepareDelete($arguments, $userId);
		if (!PlanState::matches($this->states->of(self::DELETE_TOOL, $userId, $this->deleteState($prepared)), $arguments)) {
			$plan = $this->previewDelete($arguments, $userId);
			throw new PlanChanged($plan + ['warnings' => [['message' => MailMessages::draftPlanChanged()]]]);
		}
		$this->services->draftsService($userId)->deleteMessage($userId, $prepared['draft']);
		return ['deleted_draft_id' => (int)$prepared['draft']->getId(), 'message' => MailMessages::draftDeleted()];
	}

	/** @return array{account:object,accountId:int,draft:object|null,fields:array<string,mixed>} */
	private function prepareWrite(array $arguments, string $userId): array {
		$accountId = (int)$arguments['account_id'];
		$account = $this->accounts->ownedAccount($userId, $accountId);
		if ($account === null) {
			throw new ToolFailure(MailMessages::accountNotFound());
		}
		$draft = isset($arguments['draft_id']) ? $this->draft($this->services->draftsService($userId), $userId, (int)$arguments['draft_id']) : null;
		if ($draft !== null && (int)$draft->getAccountId() !== $accountId) {
			throw new ToolFailure(MailMessages::draftNotFound());
		}
		if ($draft !== null && (array)($draft->getAttachments() ?? []) !== []) {
			throw new ToolFailure(MailMessages::draftAttachmentsCannotEdit());
		}
		if ($draft !== null && (mb_strlen((string)($draft->getBodyPlain() ?? '')) > self::MAX_BODY_CHARS || mb_strlen((string)($draft->getBodyHtml() ?? '')) > self::MAX_BODY_CHARS)) {
			throw new ToolFailure(MailMessages::draftTooLarge(self::MAX_BODY_CHARS));
		}
		$currentRecipients = $draft === null ? ['to' => [], 'cc' => [], 'bcc' => []] : $this->recipientFields($draft);
		$fields = [
			'subject' => (string)$arguments['subject'],
			'body_plain' => (string)(array_key_exists('body_plain', $arguments) ? $arguments['body_plain'] : ($draft?->getBodyPlain() ?? '')),
			'body_html' => (string)(array_key_exists('body_html', $arguments) ? $arguments['body_html'] : ($draft?->getBodyHtml() ?? '')),
			'is_html' => (bool)(array_key_exists('is_html', $arguments) ? $arguments['is_html'] : ($draft?->isHtml() ?? false)),
			'to' => array_key_exists('to', $arguments) ? $this->recipients($arguments['to']) : $currentRecipients['to'],
			'cc' => array_key_exists('cc', $arguments) ? $this->recipients($arguments['cc']) : $currentRecipients['cc'],
			'bcc' => array_key_exists('bcc', $arguments) ? $this->recipients($arguments['bcc']) : $currentRecipients['bcc'],
		];
		return compact('account', 'accountId', 'draft', 'fields');
	}

	/** @return array{draft:object,account:object} */
	private function prepareDelete(array $arguments, string $userId): array {
		$draft = $this->draft($this->services->draftsService($userId), $userId, (int)$arguments['draft_id']);
		$account = $this->accounts->ownedAccount($userId, (int)$draft->getAccountId());
		if ($account === null) {
			throw new ToolFailure(MailMessages::draftNotFound());
		}
		return compact('draft', 'account');
	}

	private function draft(object $service, string $userId, int $id): object {
		try {
			return $service->getMessage($id, $userId);
		} catch (DoesNotExistException) {
			throw new ToolFailure(MailMessages::draftNotFound());
		}
	}

	private function writeState(array $prepared): array {
		return ['accountId' => $prepared['accountId'], 'draftId' => $prepared['draft'] === null ? null : (int)$prepared['draft']->getId(),
			'before' => $prepared['draft'] === null ? null : $this->snapshot($prepared['draft']), 'after' => $prepared['fields']];
	}

	private function deleteState(array $prepared): array {
		return ['draftId' => (int)$prepared['draft']->getId(), 'accountId' => (int)$prepared['draft']->getAccountId(), 'before' => $this->snapshot($prepared['draft'])];
	}

	private function snapshot(object $draft): array {
		return ['updatedAt' => $draft->getUpdatedAt(), 'subject' => $draft->getSubject(), 'bodyPlain' => $draft->getBodyPlain(),
			'bodyHtml' => $draft->getBodyHtml(), 'attachments' => $this->attachmentRows($draft), 'recipientFingerprint' => $this->recipientFingerprint($draft)];
	}

	private function row(object $draft, bool $includeBody): array {
		$row = ['id' => (int)$draft->getId(), 'account_id' => (int)$draft->getAccountId(), 'subject' => MailRows::text((string)$draft->getSubject()),
			'is_html' => (bool)$draft->isHtml(), 'updated_at' => $draft->getUpdatedAt(), 'recipients' => $this->recipientRows($draft),
			'recipientCount' => count((array)($draft->getRecipients() ?? [])),
			'attachments' => $this->attachmentRows($draft),
			'recipientsTruncated' => count((array)($draft->getRecipients() ?? [])) > self::MAX_VISIBLE_RECIPIENTS,
			'attachmentsTruncated' => count((array)($draft->getAttachments() ?? [])) > 100];
		if ($includeBody) {
			$plain = (string)($draft->getBodyPlain() ?? '');
			$html = (string)($draft->getBodyHtml() ?? '');
			$row['body_plain'] = mb_substr($plain, 0, self::MAX_BODY_CHARS);
			$row['body_html'] = mb_substr($html, 0, self::MAX_BODY_CHARS);
			$row['bodyTruncated'] = mb_strlen($plain) > self::MAX_BODY_CHARS || mb_strlen($html) > self::MAX_BODY_CHARS;
		}
		return $row;
	}

	private function recipientRows(object $draft): array {
		$rows = [];
		foreach (array_slice((array)($draft->getRecipients() ?? []), 0, self::MAX_VISIBLE_RECIPIENTS) as $recipient) {
			$field = match ((int)$recipient->getType()) { 1 => 'to', 2 => 'cc', 3 => 'bcc', default => null };
			if ($field !== null) {
				$rows[$field][] = ['label' => MailRows::text((string)$recipient->getLabel()), 'email' => MailRows::text((string)$recipient->getEmail())];
			}
		}
		return ['to' => $rows['to'] ?? [], 'cc' => $rows['cc'] ?? [], 'bcc' => $rows['bcc'] ?? []];
	}

	/** @return array{to:list<array{label:string,email:string}>,cc:list<array{label:string,email:string}>,bcc:list<array{label:string,email:string}>} exact native recipient values for a mutation plan */
	private function recipientFields(object $draft): array {
		$rows = ['to' => [], 'cc' => [], 'bcc' => []];
		foreach ((array)($draft->getRecipients() ?? []) as $recipient) {
			$field = match ((int)$recipient->getType()) { 1 => 'to', 2 => 'cc', 3 => 'bcc', default => null };
			if ($field === null) {
				continue;
			}
			if (count($rows[$field]) >= self::MAX_RECIPIENTS) {
				throw new ToolFailure(MailMessages::draftTooManyRecipients(self::MAX_RECIPIENTS));
			}
			$rows[$field][] = ['label' => (string)$recipient->getLabel(), 'email' => (string)$recipient->getEmail()];
		}
		return array_map(static function (array $items): array {
			return array_slice($items, 0, self::MAX_RECIPIENTS);
		}, $rows);
	}

	private function recipientFingerprint(object $draft): string {
		$rows = ['to' => [], 'cc' => [], 'bcc' => []];
		foreach ((array)($draft->getRecipients() ?? []) as $recipient) {
			$field = match ((int)$recipient->getType()) { 1 => 'to', 2 => 'cc', 3 => 'bcc', default => null };
			if ($field !== null) {
				$rows[$field][] = ['label' => (string)$recipient->getLabel(), 'email' => (string)$recipient->getEmail()];
			}
		}
		return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private function attachmentRows(object $draft): array {
		return array_slice(array_map(static fn (object $a): array => ['name' => MailRows::text((string)$a->getFileName()), 'mimeType' => MailRows::text((string)$a->getMimeType())], (array)($draft->getAttachments() ?? [])), 0, 100);
	}

	/** @return list<array{label?:string,email:string}> */
	private function recipients(array $items): array {
		return array_values(array_map(static fn (array $item): array => ['email' => trim((string)$item['email'])] + (isset($item['label']) ? ['label' => trim((string)$item['label'])] : []), $items));
	}
}
