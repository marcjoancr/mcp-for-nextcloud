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
        if($tool==='mail_import_draft')return self::importDraft($plan);
        if($tool==='mail_index_messages')return self::indexMessages($plan);
        if(in_array($tool,['mail_send','mail_schedule','mail_cancel_delivery','mail_sent_repair'],true))return self::delivery($tool,$plan);
		if (!in_array($tool, ['mail_export_message', 'mail_save_attachment', 'mail_write_draft', 'mail_delete_draft', 'mail_attach_files', 'mail_write_account_settings', 'mail_write_signature', 'mail_configure_connection', 'mail_add_account', 'mail_complete_account_setup', 'mail_test_connection', 'mail_delete_account', 'mail_set_message_flag', 'mail_move_message', 'mail_archive_message', 'mail_trash_message', 'mail_restore_message', 'mail_create_mailbox', 'mail_rename_mailbox', 'mail_set_mailbox_subscription', 'mail_configure_mailboxes', 'mail_create_tag', 'mail_set_message_tag', 'mail_refresh_mailbox', 'mail_reconcile_operation', 'mail_add_alias', 'mail_update_alias', 'mail_delete_alias'], true)) {
			return null;
		}
		return match ($tool) {
			'mail_export_message' => self::export($plan),
            'mail_save_attachment' => self::attachment($plan),
			'mail_write_draft', 'mail_attach_files' => self::draft($plan),
			'mail_delete_draft' => self::deleteDraft($plan),
			default => self::configuration($tool, $plan),
		};
	}

    private static function importDraft(array $plan):string {
        $sender=$plan['target']['sender']??[];$from=$plan['source_from']??[];$lines=[Translator::t('Import a protected mail draft'),'',Translator::t('The original is retained. A new protected local draft will be created.'),
            '- '.Translator::t('Message id').': '.(int)($plan['source']['message_id']??0),'- '.Translator::t('Account id').': '.(int)($plan['target']['account_id']??0),
            '- '.Translator::t('Original sender: %s',[PlanText::quoted((string)($from['name']??'')).' '.PlanText::quoted((string)($from['email']??''))]),
            '- '.Translator::t('Sender: %s',[PlanText::quoted((string)($sender['name']??'')).' '.PlanText::quoted((string)($sender['email']??''))]),
            '- '.Translator::t('Subject: %s',[PlanText::quoted((string)($plan['subject']??''))])];
        foreach(['to','cc','bcc'] as $key)foreach($plan[$key]??[] as $i=>$recipient)$plan[$key][$i]['label']=$recipient['name']??'';$lines=array_merge($lines,self::recipientLines($plan));
        $lines[]='- '.Translator::t('Body sizes: plain %s characters; HTML %s characters.',[(int)($plan['body']['plain_characters']??0),(int)($plan['body']['html_characters']??0)]);
        if(($plan['reply_to']??null)!==null)$lines[]='- In-Reply-To: '.PlanText::quoted((string)$plan['reply_to']);
        foreach($plan['attachments']??[] as $attachment)$lines[]='- '.Translator::t('Attachment: %s',[PlanText::quoted((string)$attachment['filename'])]).' — '.(int)$attachment['byte_count'].' bytes; '.PlanText::quoted((string)$attachment['mime_type']).'; '.PlanText::quoted((string)$attachment['disposition']).'; CID '.PlanText::quoted((string)($attachment['content_id']??'')).'; SHA-256 '.PlanText::inline((string)$attachment['sha256']);
        return implode("\n",$lines);
    }
    private static function indexMessages(array $plan):string {
        $lines=[Translator::t('Index selected mail messages'),'',Translator::t('Only complete supported body text is searchable. Refused messages are excluded.')];
        foreach($plan['items']??[] as $item)$lines[]='- '.Translator::t('Message id').': '.(int)($item['source']['message_id']??0).'; '.PlanText::quoted((string)($item['coverage_state']??'')).'; '.Translator::t('Size: %s bytes',[(int)($item['text_bytes']??0)]);
        return implode("\n",$lines);
    }

    private static function export(array $plan):string {
        $destination=$plan['destination']??[];
        return implode("\n",[Translator::t('Export original mail to Files'),'',
            '- '.Translator::t('Message id').': '.(int)($plan['source']['message_id']??0),
            '- '.PlanText::quoted((string)($destination['path']??'')),
            '- '.Translator::t('Size: %s bytes',[(int)($plan['byte_count']??0)]),
            '- SHA-256: '.PlanText::inline((string)($plan['content_sha256']??'')),
            ...self::configurationFields($destination)]);
    }

    private static function delivery(string $tool,array $plan):string {
        $heading=match($tool){
            'mail_send'=>Translator::t('Approve this frozen mail for one immediate attempt.'),
            'mail_schedule'=>Translator::t('Approve this frozen mail for the scheduled instant.'),
            'mail_cancel_delivery'=>Translator::t('Cancel this queued mail before its attempt.'),
            default=>Translator::t('Copy this accepted mail to the reviewed Sent folder.'),
        };
        $lines=[$heading,''];$draft=$plan['draft']??null;
        if(is_array($draft)){
            $lines[]='- '.Translator::t('Draft id: %s',[(int)($draft['draftId']??0)]);
            $lines[]='- '.Translator::t('Account id').': '.(int)($draft['accountId']??0);
            $lines[]='- '.Translator::t('Sender: %s',[PlanText::quoted((string)($draft['sender']['name']??'')).' '.PlanText::quoted((string)($draft['sender']['email']??''))]);
            foreach(['to','cc','bcc']as$key)foreach($draft[$key]??[]as$i=>$recipient)$draft[$key][$i]['label']=$recipient['name']??'';
            $draft['body_plain']=$draft['bodyPlain']??'';$draft['body_html']=$draft['bodyHtml']??'';$draft['is_html']=($draft['bodyHtml']??null)!==null;
            $lines=array_merge($lines,self::draftFields($draft));
            foreach($draft['attachments']??[]as$attachment)$lines[]='- '.Translator::t('Attachment: %s',[PlanText::quoted((string)$attachment['name'])]).' — '.(int)($attachment['bytes']??0).' bytes; SHA256 '.PlanText::inline((string)($attachment['sha256']??''));
            $lines[]='- '.Translator::t('Sent folder').': '.(isset($draft['sentTarget']['mailboxId'])?(int)$draft['sentTarget']['mailboxId']:Translator::t('Separate repair required'));
        }
        $delivery=$plan['delivery']??[];
        foreach(['id'=>Translator::t('Delivery id'),'state'=>Translator::t('Attempt state'),'sentState'=>Translator::t('Sent-copy state'),'targetMailboxId'=>Translator::t('Sent folder')]as$key=>$label)if(isset($delivery[$key]))$lines[]='- '.$label.': '.PlanText::quoted(self::value($delivery[$key]));
        if(isset($plan['send_at']))$lines[]='- '.Translator::t('Scheduled UTC epoch').': '.(int)$plan['send_at'];
        if(isset($plan['timezone']))$lines[]='- '.Translator::t('Timezone').': '.PlanText::quoted((string)$plan['timezone']);
        if(isset($plan['sending_enabled']))$lines[]='- '.Translator::t('Transmission enabled').': '.($plan['sending_enabled']?Translator::t('yes'):Translator::t('no'));
        if(isset($plan['notice']))$lines[]=PlanText::quoted((string)$plan['notice']);
        return implode("\n",$lines);
    }

    private static function configuration(string $tool, array $plan): string {
        $heading = match ($tool) {
            'mail_reconcile_operation' => Translator::t('Record this proven Mail operation outcome. No native write will be repeated.'),
            'mail_add_alias', 'mail_update_alias', 'mail_delete_alias' => Translator::t('Apply these reviewed local Mail identity changes. Sending stays disabled.'),
            'mail_refresh_mailbox' => Translator::t('Refresh this reviewed Mail folder. Sending stays disabled.'),
            'mail_set_message_tag' => Translator::t('Set this Mail message tag to the reviewed value. Sending stays disabled.'),
            'mail_create_tag' => Translator::t('Create this local Mail tag for all your accounts. No message will be changed.'),
            'mail_write_account_settings' => Translator::t('Update these Mail preferences.'),
            'mail_write_signature' => Translator::t('Update this Mail signature. A null value removes it.'),
            'mail_configure_connection', 'mail_add_account' => Translator::t('Open the native Mail assistant. Enter credentials there and review completion separately.'),
            'mail_complete_account_setup' => Translator::t('Confirm the observed native Mail configuration. This does not prove message delivery.'),
            'mail_move_message', 'mail_archive_message', 'mail_trash_message', 'mail_restore_message' => Translator::t('Move this Mail message between the reviewed folders. Sending stays disabled.'),
            'mail_create_mailbox', 'mail_rename_mailbox', 'mail_set_mailbox_subscription', 'mail_configure_mailboxes' => Translator::t('Apply these reviewed Mail folder changes. Sending stays disabled.'),
            'mail_set_message_flag' => Translator::t('Set this Mail message flag to the reviewed value.'),
            'mail_test_connection' => Translator::t('Authenticate with the selected Mail servers. No message will be sent.'),
            'mail_delete_account' => Translator::t('Remove this Mail account from Nextcloud. Remote messages and Files are retained.'),
            default => throw new \LogicException('Unknown Mail configuration plan'),
        };
        $lines = [$heading, ''];
        if($tool==='mail_create_tag')$lines[] = Translator::t('Scope: all your Mail accounts.');
        foreach (['account_id' => Translator::t('Account id'), 'mailbox_id' => Translator::t('Folder id'), 'alias_id' => Translator::t('Alias id'), 'message_id' => Translator::t('Message id'), 'flag' => Translator::t('Flag'),
            'target_operation_id' => Translator::t('Target operation'), 'setup_operation_id' => Translator::t('Setup operation'), 'source_tool' => Translator::t('Source tool')] as $key => $label) {
            if (array_key_exists($key, $plan)) $lines[] = '- ' . $label . ': ' . PlanText::quoted(self::value($plan[$key]));
        }
        foreach (['tag' => Translator::t('Mail tag'), 'folders' => Translator::t('Role folders'), 'source' => Translator::t('Source folder'), 'destination' => Translator::t('Destination folder'), 'account' => Translator::t('Mail account'), 'before' => Translator::t('Current values'),
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
        if (isset($after['forward_source']['message_id'])) $lines[] = '- ' . Translator::t('Forward source message: %s', [(int)$after['forward_source']['message_id']]);
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
