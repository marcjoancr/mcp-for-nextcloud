<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Mail;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Modules\Mail\MailModule;
use OCA\Mcp\Modules\Mail\MailToolCatalog;

/**
 * Representative, user-readable plan payloads for every Mail write. Factories are intentionally lazy failures:
 * this fixture tests only module metadata and its production renderer, never Mail or Files side effects.
 */
final class MailWritePlanFixtures {
	/** @return array{MailModule,array<string,array<string,mixed>>} */
	public static function create(): array {
		$factories = [];
		foreach (array_keys(MailToolCatalog::definitions()) as $name) {
			$factories[$name] = static function () use ($name): Tool {
				throw new \LogicException('Plan readability tests must not resolve ' . $name);
			};
		}

		return [new MailModule($factories, new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub())), [

            'mail_import_draft' => ['action'=>'copy_to_new_protected_local_draft','source_retained'=>true,'source'=>['message_id'=>7],'target'=>['account_id'=>2,'sender'=>['name'=>'Chosen sender','email'=>'sender@example.test']],'source_from'=>['name'=>'Original sender','email'=>'source@example.test'],'subject'=>'Synthetic subject','to'=>[['name'=>'Recipient','email'=>'recipient@example.test']],'cc'=>[],'bcc'=>[],'body'=>['plain_characters'=>4,'html_characters'=>0],'reply_to'=>'<parent@example.test>','attachments'=>[['filename'=>'synthetic.bin','mime_type'=>'application/octet-stream','disposition'=>'attachment','content_id'=>null,'byte_count'=>3,'sha256'=>str_repeat('c',64)]]],
            'mail_index_messages' => ['action'=>'index_selected_messages','policy'=>'complete-text-v1','coverage'=>'supported_text_parts_only','items'=>[['source'=>['message_id'=>7],'coverage_state'=>'complete','text_bytes'=>4]]],
            'mail_export_message' => ['action'=>'export_message','source'=>['message_id'=>7],'content_sha256'=>str_repeat('c',64),'byte_count'=>42,'destination'=>['path'=>'/Shared/message.eml','access'=>['scope'=>'shared'],'shared'=>[]]],
            'mail_send' => ['action'=>'mail_send','draft'=>['subject'=>'Reviewed subject','bodyPlain'=>'Reviewed complete body','sender'=>['email'=>'alice@example.test'],'to'=>[['email'=>'recipient@example.test']]],'delivery'=>['id'=>'opaque','state'=>'queued'],'send_at'=>123,'timezone'=>'UTC','notice'=>'Acceptance is not delivery. Never repeat uncertain attempts.'],
            'mail_schedule' => ['action'=>'mail_schedule','draft'=>['subject'=>'Reviewed subject','bodyPlain'=>'Reviewed complete body','sender'=>['email'=>'alice@example.test'],'to'=>[['email'=>'recipient@example.test']]],'delivery'=>['id'=>'opaque','state'=>'queued'],'send_at'=>123,'timezone'=>'UTC','notice'=>'Acceptance is not delivery. Never repeat uncertain attempts.'],
            'mail_cancel_delivery' => ['action'=>'mail_cancel_delivery','draft'=>['subject'=>'Reviewed subject','bodyPlain'=>'Reviewed complete body','sender'=>['email'=>'alice@example.test'],'to'=>[['email'=>'recipient@example.test']]],'delivery'=>['id'=>'opaque','state'=>'queued'],'send_at'=>123,'timezone'=>'UTC','notice'=>'Acceptance is not delivery. Never repeat uncertain attempts.'],
            'mail_sent_repair' => ['action'=>'mail_sent_repair','draft'=>['subject'=>'Reviewed subject','bodyPlain'=>'Reviewed complete body','sender'=>['email'=>'alice@example.test'],'to'=>[['email'=>'recipient@example.test']]],'delivery'=>['id'=>'opaque','state'=>'queued'],'send_at'=>123,'timezone'=>'UTC','notice'=>'Acceptance is not delivery. Never repeat uncertain attempts.'],
            'mail_write_account_settings' => ['account_id'=>1,'before'=>['settings'=>['editor_mode'=>'plaintext']], 'after'=>['settings'=>['editor_mode'=>'html']]],
            'mail_write_signature' => ['account_id'=>1,'alias_id'=>null,'before'=>['signature'=>'Old signature'],'after'=>['signature'=>'New signature']],
            'mail_configure_connection' => ['account_id'=>1,'before'=>['imap'=>['host'=>'old.example.test']], 'requested'=>['imap'=>['host'=>'imap.example.test']]],
            'mail_add_account' => ['requested'=>['email'=>'alice@example.test']],
            'mail_complete_account_setup' => ['account_id'=>2,'setup_operation_id'=>'setup-1','requested'=>['email'=>'alice@example.test'],'observed'=>['imap'=>['host'=>'imap.example.test']]],
            'mail_set_message_flag' => ['account_id'=>1,'message_id'=>42,'flag'=>'seen','before'=>false,'after'=>true],
            'mail_move_message' => ['account_id'=>1,'message_id'=>42,'source'=>['id'=>3,'name'=>'INBOX'],'destination'=>['id'=>4,'name'=>'Reviewed']],
            'mail_archive_message' => ['account_id'=>1,'message_id'=>42,'source'=>['id'=>3,'name'=>'INBOX'],'destination'=>['id'=>4,'name'=>'Reviewed']],
            'mail_trash_message' => ['account_id'=>1,'message_id'=>42,'source'=>['id'=>3,'name'=>'INBOX'],'destination'=>['id'=>4,'name'=>'Reviewed']],
            'mail_restore_message' => ['account_id'=>1,'message_id'=>42,'source'=>['id'=>3,'name'=>'INBOX'],'destination'=>['id'=>4,'name'=>'Reviewed']],
            'mail_reconcile_operation' => ['account_id'=>1,'target_operation_id'=>'original-1','source_tool'=>'mail_update_alias','before'=>['state'=>'uncertain'],'after'=>['state'=>'succeeded'],'observed'=>['proof'=>'atomic_local_completion']],
            'mail_add_alias' => ['account_id'=>1,'after'=>['name'=>'New','email'=>'alias@example.test']],
            'mail_update_alias' => ['account_id'=>1,'alias_id'=>3,'before'=>['name'=>'Old','email'=>'alias@example.test'],'after'=>['name'=>'New','email'=>'alias@example.test']],
            'mail_delete_alias' => ['account_id'=>1,'alias_id'=>3,'before'=>['name'=>'Old','email'=>'alias@example.test'],'after'=>null],
            'mail_refresh_mailbox' => ['account_id'=>1,'mailbox_id'=>3,'source'=>'Reviewed','requested'=>['allow_cache_reset'=>true],'message'=>'Invalid cache will receive new cached IDs. Old IDs, cursors and plans become invalid.'],
            'mail_set_message_tag' => ['account_id'=>1,'message_id'=>42,'tag'=>['id'=>7,'name'=>'Reviewed','color'=>'#123456'],'before'=>false,'after'=>true],
            'mail_create_tag' => ['action'=>'create_tag','scope'=>'all_owned_accounts','after'=>['name'=>'Reviewed','color'=>'#123456']],
            'mail_create_mailbox' => ['account_id'=>1,'before'=>['name'=>''],'after'=>['name'=>'Reviewed']],
            'mail_rename_mailbox' => ['account_id'=>1,'mailbox_id'=>3,'before'=>['name'=>'Before'],'after'=>['name'=>'Reviewed']],
            'mail_set_mailbox_subscription' => ['account_id'=>1,'mailbox_id'=>3,'before'=>['name'=>'Reviewed','subscribed'=>false],'after'=>['name'=>'Reviewed','subscribed'=>true]],
            'mail_configure_mailboxes' => ['account_id'=>1,'before'=>['archive_mailbox_id'=>3],'after'=>['archive_mailbox_id'=>4],'folders'=>['archive_mailbox_id'=>['id'=>4,'name'=>'Reviewed']]],
            'mail_test_connection' => ['account_id'=>1,'profile'=>['imap'=>['host'=>'imap.example.test']],'protocols'=>['imap','smtp']],
            'mail_delete_account' => ['account_id'=>1,'account'=>['email'=>'alice@example.test'],'impact'=>['cache_and_workers'=>'Native Mail controls local cache and workers.']],

			'mail_save_attachment' => [
				'attachment' => ['name' => 'Quarterly report.pdf', 'message_id' => 42, 'mimeType' => 'application/pdf', 'size' => 2048],
				'destination' => ['path' => '/Shared/Quarterly report.pdf', 'folder' => '/Shared',
					'access' => ['scope' => 'shared', 'ownerDisplayName' => 'Avery'], 'shared' => []],
			],
			'mail_write_draft' => [
				'action' => 'create', 'account' => ['email' => 'alice@example.test'],
				'after' => ['subject' => 'Project update', 'body_plain' => 'The review is ready.', 'body_html' => '', 'is_html' => false,
					'to' => [['label' => 'Morgan', 'email' => 'morgan@example.test']], 'cc' => [], 'bcc' => [], 'attachments' => []],
			],
            'mail_attach_files' => ['action' => 'update', 'account' => ['email' => 'alice@example.test'], 'draft_id' => 73,
                'after' => ['subject' => 'Draft', 'body_plain' => 'Body', 'attachments' => [['name' => 'budget.pdf', 'mimeType' => 'application/pdf']]]],
			'mail_delete_draft' => [
				'action' => 'delete', 'draft' => ['id' => 73, 'subject' => 'Old proposal',
					'body_plain' => 'This local draft is no longer needed.', 'body_html' => '', 'is_html' => false,
					'recipients' => ['to' => [['label' => 'Casey', 'email' => 'casey@example.test']], 'cc' => [], 'bcc' => []],
					'attachments' => []],
			],
		]];
	}
}
