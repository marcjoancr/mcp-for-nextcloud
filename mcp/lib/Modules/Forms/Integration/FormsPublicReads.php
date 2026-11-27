<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\Presentation\FormsProjection;
use OCP\Share\IShare;

/** Reuses the stock v3 read contracts without admitting guarded effects. */
final class FormsPublicReads {
	public const TOOLS = [
		'forms_read_settings',
		'forms_read_question',
		'forms_list_shares',
		'forms_read_own_response',
		'forms_read_response',
	];

	/** @param \Closure(string, string, array): void $authorize */
	public function __construct(private FormsOcsReader $ocs, private \Closure $authorize) {
	}

	public function tools(): array {
		return $this->ocs->supportsOfficialApi() ? self::TOOLS : [];
	}

	public function read(string $uid, string $tool, array $args): array {
		if (!in_array($tool, $this->tools(), true)) {
			throw new ToolFailure(ToolFailure::FORBIDDEN);
		}
		($this->authorize)($uid, $tool, $args);
		try {
			$get = $this->ocs->publicFormReader($uid, $args['id']);
			$form = $get('form');
			$this->assertReadRole($uid, $tool, $args['id'], $form);

			$output = match ($tool) {
				'forms_read_settings' => FormsProjection::read($tool, $form, $args),
				'forms_read_question' => $this->question($get, $args),
				'forms_list_shares' => $this->shares($form, $args),
				'forms_read_own_response', 'forms_read_response' => $this->response($get, $uid, $tool, $form, $args),
			};
			// A two-endpoint read must survive a fresh Forms permission and privacy check.
			if (in_array($tool, ['forms_read_question', 'forms_read_own_response', 'forms_read_response'], true)) {
				$after = $get('form');
				$this->assertReadRole($uid, $tool, $args['id'], $after);
				if ($this->questionTypes($form) !== $this->questionTypes($after)
					|| ($form['isAnonymous'] ?? null) !== ($after['isAnonymous'] ?? null)) {
					throw new ToolFailure(ToolFailure::NOT_FOUND);
				}
			}
		} catch (\Throwable) {
			throw new ToolFailure(ToolFailure::NOT_FOUND);
		}
		($this->authorize)($uid, $tool, $args);
		return $output;
	}

	private function assertReadRole(string $uid, string $tool, int $id, array $form): void {
		if (($form['id'] ?? null) !== $id || !is_array($form['permissions'] ?? null)
			|| !is_bool($form['isAnonymous'] ?? null)
			|| ($tool === 'forms_list_shares' && ($form['ownerId'] ?? null) !== $uid)
			|| ($tool === 'forms_read_response' && !in_array('results', $form['permissions'], true))) {
			throw new ToolFailure(ToolFailure::NOT_FOUND);
		}
	}

	private function question(\Closure $get, array $args): array {
		$question = $get('question', $args['question_id']);
		if (($question['id'] ?? null) !== $args['question_id'] || ($question['formId'] ?? null) !== $args['id']) {
			throw new ToolFailure(ToolFailure::NOT_FOUND);
		}
		return FormsProjection::read('forms_read_question', $question, $args);
	}

	private function shares(array $form, array $args): array {
		$shares = $form['shares'] ?? null;
		if (!is_array($shares) || !array_is_list($shares)) {
			throw new ToolFailure(ToolFailure::NOT_FOUND);
		}
		foreach ($shares as &$share) {
			if (!is_array($share) || !is_int($share['shareType'] ?? null) || ($share['formId'] ?? null) !== $args['id']) {
				throw new ToolFailure(ToolFailure::NOT_FOUND);
			}
			if ($share['shareType'] === IShare::TYPE_LINK) {
				unset($share['shareWith'], $share['displayName']);
			}
		}
		unset($share);
		return FormsProjection::read('forms_list_shares', $shares, $args);
	}

	private function response(\Closure $get, string $uid, string $tool, array $form, array $args): array {
		$submission = $get('submission', $args['submission_id']);
		if (($submission['id'] ?? null) !== $args['submission_id'] || ($submission['formId'] ?? null) !== $args['id']
			|| !is_string($submission['userId'] ?? null) || !is_array($submission['answers'] ?? null)
			|| ($tool === 'forms_read_own_response' && $submission['userId'] !== $uid)) {
			throw new ToolFailure(ToolFailure::NOT_FOUND);
		}
		$types = $this->questionTypes($form);
		foreach ($submission['answers'] as &$answer) {
			if (!is_array($answer) || !is_int($answer['questionId'] ?? null)) {
				throw new ToolFailure(ToolFailure::NOT_FOUND);
			}
			$type = $types[$answer['questionId']] ?? null;
			if ($type === null || $type === 'file') {
				$answer['_mcp_file_answer'] = true;
			}
		}
		unset($answer);
		$submission['isAnonymous'] = $form['isAnonymous'];
		return FormsProjection::response($submission, $args);
	}

	private function questionTypes(array $form): array {
		if (!is_array($form['questions'] ?? null)) {
			throw new ToolFailure(ToolFailure::NOT_FOUND);
		}
		$types = [];
		foreach ($form['questions'] as $question) {
			if (!is_array($question) || !is_int($question['id'] ?? null) || !is_string($question['type'] ?? null)) {
				throw new ToolFailure(ToolFailure::NOT_FOUND);
			}
			$types[$question['id']] = $question['type'];
		}
		return $types;
	}
}
