<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

/** Keeps the legacy matrix intact except for explicitly reviewed additions. */
final class LegacyCatalogContract {
	/** Closed reviewed Mail additions; any unreviewed addition must fail this upgrade seam. */
	private const MAIL_ADDITIONS = ['read', 'search', 'read_message', 'read_outbox', 'read_attachment', 'save_attachment', 'read_drafts', 'write_drafts', 'delete_drafts', 'read_owned_drafts', 'write_owned_draft', 'delete_owned_draft', 'attach_files', 'read_operations', 'read_settings', 'write_settings', 'read_signatures', 'write_signatures', 'read_connection', 'configure_connection', 'test_connection', 'create_accounts', 'complete_setup', 'delete_accounts', 'set_flags', 'move_messages', 'archive_messages', 'trash_messages', 'restore_messages', 'create_mailboxes', 'rename_mailboxes', 'subscribe_mailboxes', 'configure_mailboxes', 'read_identities', 'read_tags', 'create_tags', 'tag_messages', 'refresh', 'reconcile_operations', 'create_identities', 'edit_identities', 'delete_identities', 'send', 'schedule', 'cancel_delivery', 'read_delivery', 'repair_sent', 'export_message', 'search_drafts', 'import_draft', 'index_messages', 'search_indexed_body', 'snooze', 'wake', 'cancel_snooze', 'read_snooze', 'read_filters','write_filters','read_absence','write_absence','disable_absence','read_sieve_status','transmit_filters','enable_absence','write_signature_mode'];
	/** Closed reviewed Forms additions; every operation remains explicitly denied after upgrade. */
	private const FORMS_ADDITIONS = ['read', 'read_responses', 'summarize_responses', 'export_responses', 'read_settings', 'create', 'edit', 'set_state', 'manage_locks', 'transfer', 'delete_form', 'restore', 'edit_questions', 'read_shares', 'read_links', 'share', 'public_link', 'submit', 'read_own_responses', 'edit_own_responses', 'edit_responses', 'delete_own_responses', 'delete_response', 'delete_all_responses', 'upload_response_files', 'read_response_files', 'save_response_file', 'summarize_results', 'download_results', 'export_results', 'link_results', 'read_comments', 'comment', 'edit_comments', 'delete_comments', 'read_admin_settings', 'admin_settings', 'read_operations', 'continue_operations', 'cancel_operations', 'read_respondents', 'configure_notifications'];

	private const FILES_ADDITIONS = ['description_read', 'description_edit', 'tags_read', 'tags_create', 'tags_edit', 'upload_link'];
	private const CONTACTS_ADDITIONS = ['share'];
	private const CALENDAR_ADDITIONS = ['share', 'restore'];
	private const PEOPLE_ADDITIONS = ['edit'];

	/**
	 * @param array<string, list<string>> $baseline catalog actually read from the released 1.0.8 code
	 * @param array<string, list<string>> $current candidate catalog
	 * @return array<string, list<string>> unchanged baseline operation matrix for before/after comparisons
	 * @throws \UnexpectedValueException on removal, changed operations or an unrecognized addition
	 */
	public static function baseline(array $baseline, array $current): array {
		$expected = $baseline;
		$expected['files'] = [...$baseline['files'], ...self::FILES_ADDITIONS];
		$expected['deck'] = [...$baseline['deck'], 'read_comments', 'comment', 'search'];
		$expected['contacts'] = [...$baseline['contacts'], ...self::CONTACTS_ADDITIONS];
		$expected['calendar'] = [...$baseline['calendar'], 'availability', ...self::CALENDAR_ADDITIONS];
		$expected['people'] = [...$baseline['people'], ...self::PEOPLE_ADDITIONS];
		$expected['talk'] = [...$baseline['talk'], 'publish_summary', 'search'];
		if (isset($baseline['mail']) || isset($baseline['forms']) || $current !== [...$expected, 'mail' => self::MAIL_ADDITIONS, 'forms' => self::FORMS_ADDITIONS]) {
			throw new \UnexpectedValueException('Unexpected change to legacy permissions');
		}
		return $baseline;
	}

	/**
	 * @param array<string, array<string, mixed>> $answers branch observations over the complete legacy matrix
	 * @return array<string, array<string, mixed>> all observations unchanged except the explicitly checked new matrix cell
	 * @throws \UnexpectedValueException if any new operation has a default other than explicit Deny
	 */
	public static function answers(array $answers): array {
		foreach ($answers as &$state) {
			foreach ($state['matrix'] as &$row) {
				foreach (self::FILES_ADDITIONS as $operation) {
					if (($row['grants']['files'][$operation] ?? null) !== false) {
						throw new \UnexpectedValueException("Expected Files $operation to be denied after upgrade");
					}
					unset($row['grants']['files'][$operation]);
				}
				foreach (['contacts' => self::CONTACTS_ADDITIONS, 'calendar' => self::CALENDAR_ADDITIONS, 'people' => self::PEOPLE_ADDITIONS] as $module => $operations) {
					foreach ($operations as $operation) {
						if (($row['grants'][$module][$operation] ?? null) !== false) {
							throw new \UnexpectedValueException("Expected $module.$operation to be denied after upgrade");
						}
						unset($row['grants'][$module][$operation]);
					}
				}
				if (($row['grants']['mail'] ?? null) !== array_fill_keys(self::MAIL_ADDITIONS, false)
					|| ($row['grants']['forms'] ?? null) !== array_fill_keys(self::FORMS_ADDITIONS, false)
					|| ($row['grants']['talk']['publish_summary'] ?? null) !== false
					|| ($row['grants']['talk']['search'] ?? null) !== false
					|| ($row['grants']['deck']['read_comments'] ?? null) !== false
					|| ($row['grants']['deck']['comment'] ?? null) !== false
					|| ($row['grants']['deck']['search'] ?? null) !== false
					|| ($row['grants']['calendar']['availability'] ?? null) !== false) {
					throw new \UnexpectedValueException('Unexpected default in the legacy matrix');
				}
				unset($row['grants']['deck']['search'], $row['grants']['calendar']['availability'], $row['grants']['mail'], $row['grants']['forms'], $row['grants']['deck']['read_comments'], $row['grants']['deck']['comment'], $row['grants']['talk']['publish_summary'], $row['grants']['talk']['search']);
			}
			unset($row);
		}
		unset($state);
		return $answers;
	}
    /** Separate only the lifecycle witness of the nine users created after the legacy snapshot. */
    public static function legacyPreferences(array $raw,array $actors):array {
        if(array_keys($actors)!==SnapshotProbe::uids()||count(array_unique($actors))!==9)throw new \UnexpectedValueException('Missing or duplicate snapshot actor witnesses.');
        foreach($actors as$uid=>$expected){
            if(!is_string($expected)||preg_match('/^[a-f0-9]{64}$/D',$expected)!==1)throw new \UnexpectedValueException('Invalid snapshot actor witness.');
            $cell=$raw['users'][$uid]['operation_actor_incarnation']??null;
            if($cell!==['value'=>$expected,'type'=>'STRING','lazy'=>false,'flags'=>5])throw new \UnexpectedValueException('Snapshot actor metadata changed.');
            unset($raw['users'][$uid]['operation_actor_incarnation']);
        }
        return $raw;
    }

}
