<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

use OCA\Mcp\L10n\Translator;

final class CapabilityMessages {
public const TOOL_LIST_LABELS_DESCRIPTION
		= 'Lists the labels available on a readable Deck board, with their IDs, titles and colours. Available on Nextcloud 32 through 35 when Deck is enabled.';
public const TOOL_EDIT_LABELS_DESCRIPTION = 'Adds or removes only the requested label IDs on a Deck card; preserves all other labels. Use deck_list_labels for that board. Preview first and repeat plan_state with confirm: true. At most 100 IDs per list; duplicates and already satisfied requests are unchanged; an ID cannot occur in both lists. A foreign-board label is refused. Read partial results before any further action. Available on Nextcloud 32 through 35.';
public const PARAM_LABEL_ADD = 'Label IDs to assign incrementally, from the card board (maximum 100).';
public const PARAM_LABEL_REMOVE = 'Label IDs to unassign incrementally, from the card board (maximum 100).';
public const PARAM_ATTACHMENT_ID = 'Bare native type-12 Deck file share ID returned as attachmentId by deck_list_card_files, not fileId. Legacy deck_file and folders are unsupported.';
public const TOOL_CARD_FILES_DESCRIPTION = 'Lists only visible existing native file associations of a readable Deck card. Requires deck.read and files.read, Files resource scope, exclusion tags and native node READ. No legacy deck_file or folders, no upload, no hidden count/handle; IDs are association/share IDs distinct from fileId. All visible items ordered by attachmentId; native Card attachmentCount stays omitted. Available on Nextcloud 32 through 35. An association you cannot see is omitted and answered like a missing one. Open the card in Deck without treating that answer as proof that a hidden association exists.';
public const TOOL_UNLINK_FILE_DESCRIPTION = 'Unlinks one visible native file association, preserving the original file and other shares. Requires deck.edit and files.read, active editable source and Files scope/tags/node READ; no files.share grant is needed. Revokes this type-12 share for all current/future card members, including a share created by someone else; independent shares may retain access. Preview first, repeat plan_state and the exact file_state receipt with confirm:true. file_state is accepted only on confirm; invalid/replayed/stale receipt, hidden and nonexistent association share one refusal. A confirmed absent association is a no-op only with valid original HMAC and the original file still visible. Never unlinks invisible files, legacy deck_file or folders. Available on Nextcloud 32 through 35. An editor of the card can unlink a visible association created by someone else. A folder you can already see, and an archived card or board, are refused in plain language.';
public const PARAM_DEPENDENCY_TARGET = 'Readable target card ID. For native assignDependentCard(A,B), A is cardId and B is targetId.';
public const TOOL_ADD_DEPENDENCY_DESCRIPTION = 'Adds one native outgoing Deck dependency cardId -> targetId on NC34/35. Requires deck.edit, origin EDIT and target READ. Refuses self-relations and cycles detectable in the bounded readable graph (100 nodes, 500 edges, depth 20); never promises a global DAG. Cycles through unreadable cards and the final check-to-write race cannot be excluded. Other links are preserved; duplicate is a no-op. Preview first, then confirm:true with the returned plan_state.';
public const TOOL_REMOVE_DEPENDENCY_DESCRIPTION = 'Removes only cardId -> targetId through the native Deck API on NC34/35. Requires deck.edit, origin EDIT and target READ even for removal. Hidden and nonexistent targets have the same refusal; no hidden count or handle. Missing relation is a no-op. Preview first, then confirm:true with the returned plan_state.';
public const TOOL_READ_DEPENDENCIES_DESCRIPTION = 'Reads the complete outgoing native dependency subgraph reachable through readable cards. For native assignDependentCard(A,B), B appears in A.dependentCards: originId=A and targetId=B, without automatic task blocking. Nodes sorted by ID; edges sorted by originId/targetId. No pagination: at most 100 readable nodes (including root), 500 readable edges and shortest-path depth 20; refuses rather than returning an incomplete graph. Never reports hidden IDs, counts, handles or a hidden-limit indicator. Cycles through unreadable cards cannot be detected. Available only on compatible NC34/35 native APIs; qualification targets: Deck1.18.5/1.19.0; admission ranges do not qualify every patch.';
public const PARAM_LABEL_LIMIT = 'Maximum number of labels to return.';
public const PARAM_LABEL_OFFSET = 'Number of labels to skip.';
	public static function dependenciesUnavailable(): string {
		return Translator::t('Native Deck dependency reading is unavailable on this installation.');
	}
	public static function dependencyGraphLimit(): string {
		return Translator::t('The readable dependency graph exceeds the traversal limits. No incomplete graph was returned.');
	}
	public static function dependencyGraphVisibility(): string {
		return Translator::t('This graph contains only readable cards. Cycles through unreadable cards cannot be detected; a visible graph does not prove that the global graph is acyclic. Native relations do not automatically block task progress.');
	}
	public static function errorLabelIds(): string {
		return Translator::t('use a list of positive integer label IDs, at most 100');
	}
	public static function errorLabelOverlap(): string {
		return Translator::t('a label cannot be added and removed in the same call');
	}
	public static function errorLabelEmpty(): string {
		return Translator::t('request at least one label to add or remove');
	}
	public static function errorLabelsUnavailable(): string {
		return Translator::t('The card or requested labels are unavailable for editing. Use an active card and labels from its own board.');
	}
	public static function planLabels(): string {
		return Translator::t('Only the requested label associations will change. All other labels will be preserved.');
	}
	public static function labelPlanChanged(): string {
		return Translator::t('The card, board or labels changed. Review and approve the new plan before changing labels.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}
	public static function labelsUnconfirmed(): string {
		return Translator::t('The label result could not be verified. Check the card in Deck before taking any further action; do not repeat this write.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}
	public static function labelsPartial(): string {
		return Translator::t('Label changes stopped before all requested associations were confirmed. Review the current card in Deck and request a fresh plan before any further changes.');
	}
	public static function labelStateObserved(): string {
		return Translator::t('The requested label state was observed after an error. This call may not have made that change; check the current labels before taking any further action.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}
	public static function errorNotFoundOrForbidden(): string {
		return Translator::t('Card, list or board not found, or no permission.');
	}
	public static function dependencyCycle(): string {
		return Translator::t('Self-relations and cycles detectable through readable cards are refused.');
	}
	public static function dependencyInactive(): string {
		return Translator::t('Dependency changes require active cards and boards.');
	}
	public static function dependencyPlanChanged(): string {
		return Translator::t('The readable dependency state changed. Review the new plan before confirming.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}
	public static function dependencyUnconfirmed(): string {
		return Translator::t('The dependency result could not be confirmed. Check the Deck interface; do not repeat the write automatically.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}
	public static function dependencyStateObserved(): string {
		return Translator::t('The requested relation state was observed after a failure. Another actor may have produced it; this does not prove which write changed it.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}
	public static function dependencyWriteLimitations(): string {
		return Translator::t('Only the requested relation changes. Direction is from this card to the target. Self-relations and cycles through readable cards are refused. Relations to cards you cannot read are omitted, with no count and no id. Cycles through those cards cannot be detected. A concurrent change between the final check and the native write can still create a cycle.');
	}
	public static function fileFolderRefused(): string {
		return Translator::t('Folders cannot be linked or unlinked here. Choose a file.');
	}
	public static function doesNotProveTheWholeRequest(): string {
		return Translator::t('This message does not prove that the whole request finished, and it is not a record of human approval.');
	}
	public static function filePlanChanged(): string {
		return Translator::t('The file association state changed. Request a new plan before confirming.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}
	public static function fileUnconfirmed(): string {
		return Translator::t('The file association result could not be confirmed. Check the Deck interface; do not repeat the unlink automatically.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}
	public static function fileUnlinkObserved(): string {
		return Translator::t('The association was absent after a native failure. Another actor may have removed it; this does not prove which operation removed it.')
			. ' ' . self::doesNotProveTheWholeRequest();
	}
	public static function fileUnlinkWarning(): string {
		return Translator::t('The original file is preserved. Unlinking revokes this association for all current and future card members, including when another member created it. Independent shares may still provide access. This removes only a native file share, never a legacy upload or folder.')
            . ' ' . Translator::t('You may lose your own last Deck mount. There is no automatic recovery; restoring the association may require the native Deck interface.');
	}
	public static function fileUnavailable(): string {
		return self::errorNotFoundOrForbidden();
	}
}
