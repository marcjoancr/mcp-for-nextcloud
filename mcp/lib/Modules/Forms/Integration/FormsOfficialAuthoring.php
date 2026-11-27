<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\Execution\FormsBeforeEffect;
use OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal;
use OCA\Mcp\Modules\Forms\Presentation\FormsProjection;
use OCP\IURLGenerator;

/** Translates the supported authoring tools into stock Forms v3 actions and reads back each acknowledged effect. */
final class FormsOfficialAuthoring implements FormsGateway {
	public const TOOLS = [
		'forms_create', 'forms_clone', 'forms_update', 'forms_set_state', 'forms_delete_form',
		'forms_create_question', 'forms_clone_question', 'forms_update_question', 'forms_delete_question', 'forms_reorder_questions',
		'forms_add_options', 'forms_update_option', 'forms_delete_option', 'forms_reorder_options',
		'forms_share', 'forms_unshare',
	];
	private const OWNER_ONLY = ['forms_delete_form', 'forms_share', 'forms_unshare'];
	private const LINK_SHARE = 3;

	/**
	 * @param \Closure(): bool $qualified
	 * @param \Closure(string, string, array): mixed $request
	 * @param ?\Closure(string, string, array, array): void $authorize
	 */
	public function __construct(
		private \Closure $qualified,
		private \Closure $request,
		private IURLGenerator $urls,
		private ?\Closure $authorize = null,
	) {
	}

	public function tools(): array {
		return ($this->qualified)() ? self::TOOLS : [];
	}

	public static function definition(array $definition): array {
		$definition['description'] = match ($definition['name']) {
			'forms_share' => 'Create a public link that lets anyone with the link submit. The form owner approves its publication.',
			'forms_unshare' => 'Revoke an existing public link. The affected clients lose access.',
			'forms_delete_form' => 'Permanently delete an owned form, its responses, links, comments and managed files. This operation creates no recovery copy.',
			'forms_delete_question' => 'Remove an active question from a form with a verified empty response set.',
			'forms_delete_option' => 'Remove a choice or grid item from a form with a verified empty response set.',
			default => explode(' First preview', $definition['description'], 2)[0],
		} . ' Preview and confirm the operation before dispatch. The MCP verifies the observed result and does not repeat uncertain writes. Forms does not enforce the preview revision during the write.';
		if ($definition['name'] === 'forms_share') {
			$definition['inputSchema']['properties']['kind']['enum'] = ['link'];
			$definition['inputSchema']['properties']['permissions']['items']['enum'] = ['submit'];
			$definition['inputSchema']['properties']['permissions']['maxItems'] = 1;
			unset($definition['inputSchema']['properties']['recipient']);
		}
		if (in_array($definition['name'], ['forms_share', 'forms_unshare'], true)) {
			$definition['grantAnyOf'] = ['public_link'];
		}
		return $definition;
	}

	public function capabilities(string $uid): array {
		return ['officialWriteTools' => $this->tools()];
	}

	public function snapshot(string $uid, string $action, array $input): array {
		if (!in_array($action, $this->tools(), true)) {
			throw new ToolFailure(ToolFailure::FORBIDDEN);
		}
		$impact = ['verification' => 'The MCP compares the preview again before dispatch and checks the result after dispatch. Forms does not enforce the preview revision while writing. An uncertain result is never retried automatically.'];
		if ($action === 'forms_create') {
			return ['revision' => 'official-create-v1', 'official_authoring' => true, 'impact' => $impact + [
				'creation' => 'Forms creates a private blank form, then the MCP sets its title and description. An interruption can leave a private form that needs review.',
			]];
		}
		$form = $this->form($uid, $input['id']);
		$isOwner = $form['ownerId'] === $uid;
		if ((!$isOwner && !in_array('edit', $form['permissions'], true))
			|| (!$isOwner && in_array($action, self::OWNER_ONLY, true))) {
			throw new ToolFailure(ToolFailure::FORBIDDEN);
		}
		if (in_array($action, ['forms_update', 'forms_update_question'], true) && $input['fields'] === []) {
			throw new ToolFailure('Specify at least one form or question field to update.');
		}
		$archived = ($form['state'] ?? null) === 2;
		if ($archived && !in_array($action, ['forms_set_state', 'forms_delete_form', 'forms_clone'], true)) {
			throw new ToolFailure('Reopen the archived form before editing it.');
		}
		if ($action === 'forms_set_state' && !$isOwner && ($archived || $input['state'] === 2)) {
			throw new ToolFailure('Only the owner can archive or reopen an archived form.');
		}
		$lockedBy = $form['lockedBy'] ?? null;
		$lockedUntil = $form['lockedUntil'] ?? null;
		if (!in_array($action, ['forms_clone', 'forms_delete_form'], true) && $lockedBy !== null && $lockedBy !== $uid
			&& ($lockedUntil === 0 || (is_int($lockedUntil) && $lockedUntil >= time()))) {
			throw new ToolFailure('Another user is editing this form.');
		}
		$question = isset($input['question_id']) ? $this->row($form['questions'], $input['question_id']) : null;
		if (isset($input['option_id'])) {
			$this->row($question['options'] ?? [], $input['option_id']);
		}
		$shares = [];
		if ($action === 'forms_share') {
			if ($input['kind'] !== 'link' || isset($input['recipient']) || $input['permissions'] !== ['submit']) {
				throw new ToolFailure('Official Forms can create only a submit-only public link through this tool.');
			}
			$impact['audience'] = 'Anyone with the link can submit without a Nextcloud account. Results require independent permission.';
		} elseif ($action === 'forms_unshare') {
			$share = $this->row($form['shares'], $input['share_id']);
			if (($share['shareType'] ?? null) !== self::LINK_SHARE) {
				throw new ToolFailure('Official Forms can revoke only public links through this tool.');
			}
			$shares[] = array_intersect_key($share, array_flip(['id', 'formId', 'shareType', 'permissions']));
			$impact['audience'] = 'People using this public link will lose access.';
		}
		if ($action === 'forms_clone') {
			$impact['clone'] = 'Creates a private copy of the active questions and options. The original form, its responses and links remain available in Forms.';
		}
		if ($action === 'forms_delete_form') {
			$impact['deletion'] = 'Permanent native deletion of the form and its questions, responses, links, comments and managed files. This operation does not create a recovery copy.';
			$impact['submissionCount'] = $form['submissionCount'] ?? null;
		}
		if (in_array($action, ['forms_delete_question', 'forms_delete_option'], true)) {
			// OCS stops returning answers to hidden questions. Keep established response history
			// in the original form and let the owner archive or revise a copy.
			if (($form['submissionCount'] ?? null) !== 0) {
				throw new ToolFailure('Removal requires a form with a verified empty response set. Preserve the existing form and revise a copy.');
			}
			$impact['deletion'] = 'Removes the selected item from the active form. Forms may also remove files linked to a removed file question.';
		}
		if (in_array($action, ['forms_create_question', 'forms_clone_question'], true) && ($input['position'] ?? 0) > count($form['questions']) + 1) {
			throw new ToolFailure('Question position exceeds the current form length.');
		}
		if ($action === 'forms_reorder_questions') {
			$this->assertExactOrder($input['order'], array_column($form['questions'], 'id'));
		}
		if ($action === 'forms_reorder_options') {
			$this->assertExactOrder($input['order'], $this->optionIds($question, $input['option_type'] ?? 'choice'));
		}

		return [
			'revision' => hash('sha256', json_encode(FormsOperationJournal::canonical($form), JSON_THROW_ON_ERROR)),
			'official_authoring' => true,
			'form' => array_intersect_key($form, array_flip([...FormsProjection::FORM_FIELDS, 'questions'])),
			'shares' => $shares,
			'impact' => $impact,
			'require_shared_confirmation' => in_array($action, self::OWNER_ONLY, true)
				|| ($action !== 'forms_clone' && ($form['shares'] !== [] || ($form['access']['permitAllUsers'] ?? false) === true)),
		];
	}

	public function apply(string $uid, string $action, array $input, string $revision, string $operationKey): array {
		try {
			$snapshot = $this->snapshot($uid, $action, $input);
			if (!hash_equals($snapshot['revision'], $revision)) {
				throw new ToolFailure('Forms changed after approval. Review a new preview.');
			}
			$this->authorize?->__invoke($uid, $action, $input, $snapshot);
		} catch (\Throwable) {
			throw new FormsBeforeEffect('Forms authorization or data changed before dispatch. Review a new preview.');
		}

		$reference = isset($input['id']) ? ['form_id' => $input['id']] : [];
		try {
			if (in_array($action, ['forms_create', 'forms_clone'], true)) {
				$source = $action === 'forms_clone' ? ['fromId' => $input['id']] : [];
				$created = ($this->request)($uid, 'newForm', $source);
				$id = $this->acknowledgedId($created);
				$reference['form_id'] = $id;
				if (($created['ownerId'] ?? null) !== $uid) {
					throw new ToolFailure('Forms did not acknowledge the expected owner.');
				}
				$fields = array_intersect_key($input, array_flip(['title', 'description']));
				if ($fields !== []) {
					$this->authorize?->__invoke($uid, $action, $input, $snapshot);
					$ack = ($this->request)($uid, 'updateForm', ['formId' => $id, 'keyValuePairs' => $fields]);
					$this->assertResult($ack === $id);
				}
				$form = $this->form($uid, $id);
				$this->assertResult($form['ownerId'] === $uid && $form['shares'] === [] && ($form['submissionCount'] ?? null) === 0
					&& ($form['access']['permitAllUsers'] ?? null) === false && $this->matches($form, $fields)
					&& $this->questionStructure($form['questions']) === $this->questionStructure($snapshot['form']['questions'] ?? []));
				$data = array_intersect_key($form, array_flip(['id', 'title', 'description']));
			} else {
				$data = $this->write($uid, $action, $input, $snapshot['form']);
			}
			foreach (['questionId' => 'question_id', 'shareId' => 'share_id', 'optionId' => 'option_id'] as $key => $name) {
				if (isset($data[$key])) {
					$reference[$name] = $data[$key];
				}
			}
			return ['state' => 'succeeded', 'data' => FormsProjection::bounded($data), 'references' => $reference];
		} catch (\Throwable) {
			// A request may have succeeded even when the transport, acknowledgement or read-back failed.
			return ['state' => 'uncertain', 'references' => $reference];
		}
	}

	private function write(string $uid, string $action, array $input, array $before): array {
		$id = $input['id'];
		$form = ['formId' => $id];
		$qid = $input['question_id'] ?? null;
		$q = $form + ['questionId' => $qid];
		$oid = $input['option_id'] ?? null;
		$option = $q + ['optionId' => $oid];
		$optionType = $input['option_type'] ?? 'choice';
		$fields = $input['fields'] ?? [];
		if ($action === 'forms_set_state') {
			$fields = ['state' => $input['state']];
		} elseif ($action === 'forms_update' && isset($fields['access'])) {
			$fields['access'] = array_replace($before['access'] ?? [], $fields['access']);
		} elseif ($action === 'forms_update_question' && isset($fields['extraSettings'])) {
			$old = $this->row($before['questions'], $qid);
			$fields['extraSettings'] = array_replace((array)($old['extraSettings'] ?? []), $fields['extraSettings']);
		}

		[$method, $args] = match ($action) {
			'forms_update', 'forms_set_state' => ['updateForm', $form + ['keyValuePairs' => $fields]],
			'forms_delete_form' => ['deleteForm', $form],
			'forms_create_question' => ['newQuestion', $form + array_intersect_key($input, array_flip(['type', 'text', 'subtype', 'position']))],
			'forms_clone_question' => ['newQuestion', $form + ['fromId' => $qid] + array_intersect_key($input, ['position' => true])],
			'forms_update_question' => ['updateQuestion', $q + ['keyValuePairs' => $fields]],
			'forms_delete_question' => ['deleteQuestion', $q],
			'forms_reorder_questions' => ['reorderQuestions', $form + ['newOrder' => $input['order']]],
			'forms_add_options' => ['newOption', $q + ['optionTexts' => $input['texts'], 'optionType' => $optionType]],
			'forms_update_option' => ['updateOption', $option + ['keyValuePairs' => ['text' => $input['text']]]],
			'forms_delete_option' => ['deleteOption', $option],
			'forms_reorder_options' => ['reorderOptions', $q + ['newOrder' => $input['order'], 'optionType' => $optionType]],
			'forms_share' => ['newShare', $form + ['shareType' => self::LINK_SHARE, 'permissions' => ['submit']]],
			'forms_unshare' => ['deleteShare', $form + ['shareId' => $input['share_id']]],
		};
		$ack = ($this->request)($uid, $method, $args);
		if ($action === 'forms_delete_form') {
			$owned = ($this->request)($uid, 'getForms', ['type' => 'owned']);
			$this->assertResult($ack === $id && is_array($owned) && array_is_list($owned)
				&& !in_array($id, array_column($owned, 'id'), true));
			return ['id' => $id, 'deleted' => true];
		}

		$after = $this->form($uid, $id);
		$this->assertResult($after['ownerId'] === $before['ownerId']
			&& ($after['ownerId'] === $uid || in_array('edit', $after['permissions'], true)));
		if ($action === 'forms_share') {
			$share = $this->row($after['shares'], $this->acknowledgedId($ack));
			$token = $share['shareWith'] ?? null;
			$this->assertResult(($share['shareType'] ?? null) === self::LINK_SHARE && ($share['permissions'] ?? null) === ['submit']
				&& is_string($token) && preg_match('/^[a-zA-Z0-9_-]{1,256}$/D', $token) === 1
				&& $token === ($ack['shareWith'] ?? null));
			return ['id' => $id, 'shareId' => $share['id'], 'permissions' => ['submit'],
				'url' => $this->urls->linkToRouteAbsolute('forms.page.publicLinkView', ['hash' => $token])];
		}
		if (in_array($action, ['forms_create_question', 'forms_clone_question'], true)) {
			$newId = $this->acknowledgedId($ack);
			$created = $this->row($after['questions'], $newId);
			$expected = $action === 'forms_create_question' ? ['type' => $input['type'], 'text' => $input['text']]
				: array_intersect_key($this->row($before['questions'], $qid), array_flip(['type', 'text']));
			if (isset($input['subtype'])) {
				$expected['extraSettings'] = ['questionType' => $input['subtype']];
			}
			if (isset($input['position'])) {
				$expected['order'] = $input['position'];
			}
			$this->assertResult(!in_array($newId, array_column($before['questions'], 'id'), true) && $this->matches($created, $expected));
			return ['id' => $id, 'questionId' => $newId] + array_intersect_key($created, array_flip(['type', 'text', 'order']));
		}

		$question = $qid !== null && $action !== 'forms_delete_question' ? $this->row($after['questions'], $qid) : null;
		$verified = match ($action) {
			'forms_update', 'forms_set_state' => $ack === $id && $this->matches($after, $fields),
			'forms_update_question' => $ack === $qid && $this->matches($question, $fields),
			'forms_delete_question' => $ack === $qid && !in_array($qid, array_column($after['questions'], 'id'), true),
			'forms_reorder_questions' => is_array($ack) && array_column($after['questions'], 'id') === $input['order'],
			'forms_add_options' => $this->verifyAddedOptions($question['options'], $ack, $input['texts'], $optionType),
			'forms_update_option' => $ack === $oid && ($this->row($question['options'], $oid)['text'] ?? null) === $input['text'],
			'forms_delete_option' => $ack === $oid && !in_array($oid, array_column($question['options'], 'id'), true),
			'forms_reorder_options' => is_array($ack) && $this->optionIds($question, $optionType) === $input['order'],
			'forms_unshare' => $ack === $input['share_id'] && !in_array($input['share_id'], array_column($after['shares'], 'id'), true),
		};
		$this->assertResult($verified);
		$data = ['id' => $id];
		foreach (['question_id' => 'questionId', 'option_id' => 'optionId', 'share_id' => 'shareId'] as $source => $key) {
			if (isset($input[$source])) {
				$data[$key] = $input[$source];
			}
		}
		if ($action === 'forms_add_options') {
			$data['options'] = array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['id', 'text', 'optionType'])), $ack);
		}
		return $data;
	}

	private function questionStructure(array $questions): array {
		return array_map(static function (array $question): array {
			return array_intersect_key($question, array_flip(['type', 'text', 'description', 'name', 'isRequired']))
				+ ['extraSettings' => (array)($question['extraSettings'] ?? []), 'options' => array_map(static fn (array $option): array => array_intersect_key($option, array_flip(['text', 'order', 'optionType'])), $question['options'])];
		}, $questions);
	}

	private function form(string $uid, int $id): array {
		$form = ($this->request)($uid, 'getForm', ['formId' => $id]);
		if (!is_array($form) || ($form['id'] ?? null) !== $id || !is_string($form['ownerId'] ?? null)
			|| !is_array($form['permissions'] ?? null) || !is_array($form['questions'] ?? null)
			|| !is_array($form['shares'] ?? null)) {
			throw new ToolFailure(ToolFailure::NOT_FOUND);
		}
		return $form;
	}

	private function row(array $rows, int $id): array {
		foreach ($rows as $item) {
			if (($item['id'] ?? null) === $id) {
				return $item;
			}
		}
		throw new ToolFailure(ToolFailure::NOT_FOUND);
	}

	private function acknowledgedId(mixed $data): int {
		$id = is_array($data) ? ($data['id'] ?? null) : null;
		if (!is_int($id) || $id <= 0) {
			throw new ToolFailure('Forms did not acknowledge the created resource.');
		}
		return $id;
	}

	private function assertExactOrder(array $requested, array $current): void {
		sort($requested, SORT_NUMERIC);
		sort($current, SORT_NUMERIC);
		if ($requested !== $current) {
			throw new ToolFailure('Specify the exact current set of Forms items once each.');
		}
	}

	private function optionIds(array $question, string $type): array {
		return array_column(array_filter($question['options'] ?? [], static fn (array $row): bool => ($row['optionType'] ?? null) === $type), 'id');
	}

	private function verifyAddedOptions(array $options, mixed $ack, array $texts, string $type): bool {
		if (!is_array($ack) || count($ack) !== count($texts)) {
			return false;
		}
		foreach (array_values($ack) as $index => $data) {
			$row = $this->row($options, $this->acknowledgedId($data));
			if (($row['optionType'] ?? null) !== $type || ($row['text'] ?? null) !== $texts[$index]) {
				return false;
			}
		}
		return true;
	}

	private function matches(array $data, array $expected): bool {
		foreach ($expected as $key => $value) {
			if (!array_key_exists($key, $data)) {
				return false;
			}
			$actual = $data[$key];
			if (is_array($value)) {
				if ((!is_array($actual) && !$actual instanceof \stdClass)
					|| (array_is_list($value) && count((array)$actual) !== count($value))
					|| !$this->matches((array)$actual, $value)) {
					return false;
				}
			} elseif ($actual !== $value) {
				return false;
			}
		}
		return true;
	}

	private function assertResult(bool $verified): void {
		if (!$verified) {
			throw new ToolFailure('The acknowledged Forms change could not be verified.');
		}
	}

	public function read(string $uid, string $action, array $input): array {
		throw new ToolFailure(ToolFailure::FORBIDDEN);
	}

	public function lookup(string $uid, string $operationKey, ?array $context = null): ?array {
		// Stock Forms has no native receipt keyed by an MCP operation.
		return null;
	}
}
