<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Presentation;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Confirmation\PlanText;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Files\Presentation\PlanFormatting;
use OCA\Mcp\Modules\Mail\Drafts\MailDraftOperations;

/** Renders complete draft and file plans without truncating body text or interpreting it as Markdown. */
final class MailPlanRenderer {
	public function render(string $tool, array $plan): ?string {
		if (!in_array($tool, ['mail_save_attachment', 'mail_write_draft', 'mail_delete_draft'], true)) {
			return null;
		}
		return match ($tool) {
			'mail_save_attachment' => self::attachment($plan),
			'mail_write_draft' => self::draft($plan),
			'mail_delete_draft' => self::deleteDraft($plan),
			default => null,
		};
	}

	private static function attachment(array $plan): string {
		$a = (array)($plan['attachment'] ?? []);
		$d = (array)($plan['destination'] ?? []);
		$lines = [Translator::t('Save this mail attachment in Files.'), '',
			'- ' . Translator::t('Attachment: %s', [PlanText::quoted((string)($a['name'] ?? ''))]),
			'- ' . Translator::t('Message id: %s', [(int)($a['message_id'] ?? 0)]),
			'- ' . Translator::t('Type: %s', [PlanText::quoted((string)($a['mimeType'] ?? ''))]),
			'- ' . Translator::t('Size: %s bytes', [(int)($a['size'] ?? 0)]),
			'- ' . Translator::t('Destination: %s', [PlanText::quoted((string)($d['path'] ?? ''))]),
		];
		$lines = array_merge($lines, PlanFormatting::folderLine($d), PlanFormatting::sharing($d));
		$access = (array)($d['access'] ?? []);
		if (isset($access['scope']) && is_string($access['scope'])) {
			$lines[] = '- ' . Translator::t('Files access scope: %s', [PlanText::inline($access['scope'])]);
		}
		if (isset($access['ownerDisplayName']) && is_string($access['ownerDisplayName'])) {
			$lines[] = '- ' . Translator::t('Files owner: %s', [PlanText::quoted($access['ownerDisplayName'])]);
		}
		return implode("\n", $lines);
	}

	private static function draft(array $plan): string {
		$after = (array)($plan['after'] ?? []);
		$lines = [Translator::t('Save this local unsent draft.'), '',
			'- ' . Translator::t('Action: %s', [PlanText::inline(match ($plan['action'] ?? null) { 'create' => Translator::t('create'), 'update' => Translator::t('update'), default => Translator::t('change') })]),
			'- ' . Translator::t('Mail account: %s', [PlanText::quoted((string)($plan['account']['email'] ?? ''))]),
		];
		if (isset($plan['draft_id'])) {
			$lines[] = '- ' . Translator::t('Draft id: %s', [(int)$plan['draft_id']]);
		}
		if (is_array($plan['before'] ?? null)) {
			$lines = array_merge($lines, ['', Translator::t('Current draft:'), ...self::draftFields((array)$plan['before'])]);
		}
		return implode("\n", array_merge($lines, ['', Translator::t('Draft after this change:'), ...self::draftFields($after)]));
	}

	private static function deleteDraft(array $plan): string {
		$draft = (array)($plan['draft'] ?? []);
		return implode("\n", [Translator::t('Delete this local unsent draft.'), '',
			'- ' . Translator::t('Draft id: %s', [(int)($draft['id'] ?? 0)]),
			...self::draftFields($draft),
			'- ' . Translator::t('This removes the local draft and its stored draft attachments. Nothing is sent.'),
		]);
	}

	/** @return list<string> */
	private static function draftFields(array $draft): array {
		$lines = ['- ' . Translator::t('Subject: %s', [PlanText::quoted((string)($draft['subject'] ?? ''))])];
		$lines = array_merge($lines, self::recipientLines((array)($draft['recipients'] ?? $draft)));
		if (array_key_exists('body_plain', $draft)) {
			$lines[] = '- ' . Translator::t('Plain body: %s', [PlanText::quoted((string)$draft['body_plain'])]);
		}
		if (array_key_exists('body_html', $draft)) {
			$lines[] = '- ' . Translator::t('HTML body: %s', [PlanText::quoted((string)$draft['body_html'])]);
		}
		if (($draft['bodyTruncated'] ?? false) === true) {
			$lines[] = '- ' . Translator::t('Body preview truncated at %s characters.', [MailDraftOperations::MAX_BODY_CHARS]);
		}
		if (($draft['recipientsTruncated'] ?? false) === true || ($draft['attachmentsTruncated'] ?? false) === true) {
			$lines[] = '- ' . Translator::t('This preview omits some recipients or attachments. Review the complete draft in Mail before confirming.');
		}
		$lines[] = '- ' . Translator::t('HTML mode: %s', [($draft['is_html'] ?? false) ? Translator::t('yes') : Translator::t('no')]);
		return array_merge($lines, self::attachmentLines((array)($draft['attachments'] ?? [])));
	}

	/** @return list<string> */
	private static function recipientLines(array $recipients): array {
		$lines = [];
		foreach (['to', 'cc', 'bcc'] as $key) {
			$values = [];
			foreach ((array)($recipients[$key] ?? []) as $recipient) {
				if (is_array($recipient)) {
					$values[] = PlanText::quoted((string)($recipient['label'] ?? '')) . ' <' . PlanText::quoted((string)($recipient['email'] ?? '')) . '>';
				}
			}
			$value = $values === [] ? Translator::t('none') : implode(', ', $values);
			$lines[] = '- ' . match ($key) {
				'to' => Translator::t('To: %s', [$value]),
				'cc' => Translator::t('Cc: %s', [$value]),
				'bcc' => Translator::t('Bcc: %s', [$value]),
			};
		}
		return $lines;
	}

	/** @return list<string> */
	private static function attachmentLines(array $attachments): array {
		$lines = [];
		foreach (array_slice($attachments, 0, 100) as $attachment) {
			if (is_array($attachment)) {
				$lines[] = '- ' . Translator::t('Draft attachment: %s (%s)', [PlanText::quoted((string)($attachment['name'] ?? '')), PlanText::quoted((string)($attachment['mimeType'] ?? ''))]);
			}
		}
		return $lines;
	}
}
