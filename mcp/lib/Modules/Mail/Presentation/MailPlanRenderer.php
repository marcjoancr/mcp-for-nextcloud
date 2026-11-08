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
		if (!in_array($tool, ['mail_save_attachment', 'mail_write_draft', 'mail_delete_draft', 'mail_attach_files', 'mail_write_account_settings', 'mail_write_signature', 'mail_configure_connection', 'mail_add_account', 'mail_complete_account_setup', 'mail_test_connection', 'mail_delete_account'], true)) {
			return null;
		}
		return match ($tool) {
			'mail_save_attachment' => self::attachment($plan),
			'mail_write_draft', 'mail_attach_files' => self::draft($plan),
			'mail_delete_draft' => self::deleteDraft($plan),
			default => self::configuration($tool, $plan),
		};
	}

    private static function configuration(string $tool, array $plan): string {
        $heading = match ($tool) {
            'mail_write_account_settings' => Translator::t('Update these Mail preferences.'),
            'mail_write_signature' => Translator::t('Update this Mail signature. A null value removes it.'),
            'mail_configure_connection', 'mail_add_account' => Translator::t('Open the native Mail assistant. Enter credentials there and review completion separately.'),
            'mail_complete_account_setup' => Translator::t('Confirm the observed native Mail configuration. This does not prove message delivery.'),
            'mail_test_connection' => Translator::t('Authenticate with the selected Mail servers. No message will be sent.'),
            'mail_delete_account' => Translator::t('Remove this Mail account from Nextcloud. Remote messages and Files are retained.'),
            default => throw new \LogicException('Unknown Mail configuration plan'),
        };
        $lines = [$heading, ''];
        foreach (['account_id' => Translator::t('Account id'), 'alias_id' => Translator::t('Alias id'),
            'setup_operation_id' => Translator::t('Setup operation'), 'source_tool' => Translator::t('Source tool')] as $key => $label) {
            if (array_key_exists($key, $plan)) $lines[] = '- ' . $label . ': ' . PlanText::quoted(self::value($plan[$key]));
        }
        foreach (['account' => Translator::t('Mail account'), 'before' => Translator::t('Current values'),
            'after' => Translator::t('Values after this change'), 'requested' => Translator::t('Requested configuration'),
            'observed' => Translator::t('Observed configuration'), 'profile' => Translator::t('Mail connection'),
            'protocols' => Translator::t('Protocols'), 'impact' => Translator::t('Removal impact')] as $key => $label) {
            if (array_key_exists($key, $plan)) $lines = array_merge($lines, ['', $label . ':', ...self::configurationFields($plan[$key])]);
        }
        if (isset($plan['message'])) $lines[] = PlanText::quoted((string)$plan['message']);
        return implode("\n", $lines);
    }

    /** Complete values, including signature HTML, are quoted as inert text. */
    private static function configurationFields(mixed $value, string $prefix = ''): array {
        if (!is_array($value)) return ['- ' . PlanText::quoted(self::value($value))];
        $lines = [];
        foreach ($value as $key => $entry) {
            if ($key === 'revision') continue; // Opaque proof, not a setting a person can change.
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            if (is_array($entry)) $lines = array_merge($lines, self::configurationFields($entry, $path));
            else $lines[] = '- ' . PlanText::inline($path) . ': ' . PlanText::quoted(self::value($entry));
        }
        return $lines;
    }
    private static function value(mixed $value): string {
        if ($value === null) return 'null';
        if (is_bool($value)) return $value ? 'true' : 'false';
        return is_scalar($value) ? (string)$value : '';
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
        if (isset($after['sender']['email'])) $lines[] = '- ' . Translator::t('Sender: %s', [PlanText::quoted((string)$after['sender']['email'])]);
        if (isset($after['in_reply_to'])) $lines[] = '- ' . Translator::t('Reply parent: %s', [PlanText::quoted((string)$after['in_reply_to'])]);
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
				$lines[] = '- ' . Translator::t('Draft attachment: %s (%s)', [PlanText::quoted((string)($attachment['name'] ?? '')), PlanText::quoted((string)($attachment['mimeType'] ?? $attachment['mime_type'] ?? ''))]);
                if (isset($attachment['path'])) $lines[] = '  - ' . Translator::t('Source file: %s', [PlanText::quoted((string)$attachment['path'])]);
                if (isset($attachment['size'])) $lines[] = '  - ' . Translator::t('Size: %s bytes', [(int)$attachment['size']]);
			}
		}
		return $lines;
	}
}
