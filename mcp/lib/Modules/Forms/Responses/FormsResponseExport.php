<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Responses;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Contracts\IncomingFileWriter;
use OCA\Mcp\Modules\Forms\FormsOutput;
use OCA\Mcp\Modules\Forms\FormsResponseReader;

/** Creates a formula-safe, bounded CSV plan and writes only through the native Files boundary. */
final class FormsResponseExport {
	public const MAX_CSV_BYTES = 1_048_576;
	public const MAX_ROWS = 8_000;
	public const MESSAGE = 'Export this bounded response CSV to Files after reviewing the destination.';

	public function __construct(private FormsResponseReader $reader, private IncomingFileWriter $files, private PlanState $states) {}

	/** @param array<string,mixed> $arguments @return array<string,mixed> */
	public function preview(string $userId, array $arguments): array {
		$plan = $this->plan($userId, $arguments);
		unset($plan['_content']);
		return $plan;
	}

	/** @param array<string,mixed> $arguments @return array<string,mixed> */
	public function execute(string $userId, array $arguments): array {
		$plan = $this->plan($userId, $arguments);
		if (($arguments['confirm'] ?? false) !== true) {
			unset($plan['_content']);
			return $plan;
		}
		PlanState::require($arguments);
		if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
			unset($plan['_content']);
			throw new PlanChanged($plan + ['warnings' => [['message' => Translator::t('The response data or Files destination changed; review the updated export plan.')]]]);
		}
		$written = $this->files->save($userId, $plan['destination']['path'], $plan['_content'], (bool)($arguments['confirm_shared'] ?? false));
		if (($written['requiresConfirmation'] ?? false) === true) {
			unset($plan['_content']);
			return $written + ['message' => $plan['message'], 'formId' => $plan['formId'], 'rows' => $plan['rows']];
		}
		return ['exported' => true, 'formId' => $plan['formId'], 'rows' => $plan['rows'], 'partial' => $plan['partial'], 'nextOffset' => $plan['nextOffset'], 'skippedRows' => $plan['skippedRows'], 'truncatedCells' => $plan['truncatedCells'], 'fileAnswersOmitted' => $plan['fileAnswersOmitted'], 'file' => $written];
	}

	/** @param array<string,mixed> $arguments @return array<string,mixed> */
	private function plan(string $userId, array $arguments): array {
		$formId = (int)$arguments['id'];
		$offset = max(0, (int)($arguments['offset'] ?? 0));
		$limit = min(max(1, (int)($arguments['limit'] ?? FormsOutput::DEFAULT_SUBMISSIONS)), FormsOutput::MAX_SUBMISSIONS);
		$path = (string)$arguments['path'];
		if (!str_ends_with(strtolower($path), '.csv')) {
			throw new \InvalidArgumentException(Translator::t('The destination path must end in .csv.'));
		}
		$page = $this->reader->readResponses($userId, $formId, $offset, $limit);
		if ($page === null) {
			throw new ToolFailure(ToolFailure::NOT_FOUND);
		}
		[$content, $rows, $partial, $skipped, $truncatedCells, $fileAnswersOmitted, $answersTruncated, $rowOrByteBoundReached] = $this->csv($page, $limit);
		$questionsTruncated = ($page['questionsTruncated'] ?? false) || count($page['questions'] ?? []) > FormsOutput::MAX_QUESTIONS;
		$nextOffset = count($page['submissions']) > $limit ? $offset + $limit : null;
		$destination = $this->files->preview($userId, $path, strlen($content));
		$source = [
			'formId' => $formId, 'offset' => $offset, 'limit' => $limit,
			'isAnonymous' => (bool)$page['isAnonymous'], 'rows' => $rows, 'csv_sha256' => hash('sha256', $content),
			'partial' => $partial, 'skippedRows' => $skipped, 'truncatedCells' => $truncatedCells,
			'fileAnswersOmitted' => $fileAnswersOmitted,
			'questionsTruncated' => $questionsTruncated, 'answersTruncated' => $answersTruncated,
			'rowOrByteBoundReached' => $rowOrByteBoundReached,
			'nextOffset' => $nextOffset,
		];
		$shown = ['source' => $source, 'destination' => $destination];
		return [
			'action' => 'export_responses', 'requiresConfirmation' => true,
			'message' => Translator::t('Export this bounded response CSV to Files after reviewing the destination.'), 'formId' => $formId, 'offset' => $offset, 'limit' => $limit,
			'rows' => $rows, 'partial' => $partial, 'skippedRows' => $skipped, 'truncatedCells' => $truncatedCells,
			'fileAnswersOmitted' => $fileAnswersOmitted, 'nextOffset' => $nextOffset,
			'questionsTruncated' => $questionsTruncated, 'answersTruncated' => $answersTruncated,
			'rowOrByteBoundReached' => $rowOrByteBoundReached,
			'destination' => $destination,
			PlanState::ARGUMENT => $this->states->of('forms_export_responses', $userId, $shown),
			'_content' => $content,
		];
	}

	/** @param array<string,mixed> $page @return array{string,int,bool,int,int,bool,bool,bool} */
	private function csv(array $page, int $limit): array {
		$questions = [];
		foreach ($page['questions'] ?? [] as $question) {
			if (is_array($question) && is_int($question['id'] ?? null)) {
				$questions[$question['id']] = is_string($question['text'] ?? null) ? $question['text'] : '';
			}
		}
		$fileIds = $page['fileQuestionIds'] ?? [];
		$submissions = array_slice($page['submissions'], 0, $limit);
		$hasMore = count($page['submissions']) > $limit;
		$partial = $hasMore;
		$skipped = 0;
		$truncatedCells = 0;
		$fileAnswersOmitted = false;
		$answersTruncated = false;
		$rowOrByteBoundReached = false;
		$questionsTruncated = ($page['questionsTruncated'] ?? false) || count($page['questions'] ?? []) > FormsOutput::MAX_QUESTIONS;
		$partial = $partial || $questionsTruncated;
		$rows = 0;
		$content = $this->line(['submission_id', 'timestamp', 'question_id', 'question', 'answer']);
		foreach ($submissions as $submission) {
			if (!is_array($submission) || !is_array($submission['answers'] ?? null)) {
				continue;
			}
			$answers = array_slice($submission['answers'], 0, FormsOutput::MAX_ANSWERS_PER_SUBMISSION);
			if (count($submission['answers']) > count($answers)) {
				$partial = true;
				$answersTruncated = true;
				$skipped += count($submission['answers']) - count($answers);
			}
			foreach ($answers as $answer) {
				if (!is_array($answer) || !is_int($answer['questionId'] ?? null)) {
					continue;
				}
				$qid = $answer['questionId'];
				if (in_array($qid, $fileIds, true)) {
					$fileAnswersOmitted = true;
					continue;
				}
				if (!isset($questions[$qid])) {
					if ($questionsTruncated) {
						$partial = true;
						$skipped++;
					}
					continue;
				}
				if (!is_string($answer['text'] ?? null) || $answer['text'] === '') {
					continue;
				}
				if ($rows >= self::MAX_ROWS) {
					$partial = true;
					$rowOrByteBoundReached = true;
					$skipped++;
					continue;
				}
				$question = $questions[$qid];
				$answerText = $answer['text'];
				if (strlen($question) > 1024) { $question = mb_strcut($question, 0, 1024, 'UTF-8'); $truncatedCells++; $partial = true; }
				if (strlen($answerText) > 1024) { $answerText = mb_strcut($answerText, 0, 1024, 'UTF-8'); $truncatedCells++; $partial = true; }
				$record = $this->line([
					(string)($submission['id'] ?? ''), (string)($submission['timestamp'] ?? ''), (string)$qid,
					$question, $answerText,
				]);
				if (strlen($content) + strlen($record) > self::MAX_CSV_BYTES) {
					$partial = true;
					$rowOrByteBoundReached = true;
					$skipped++;
					continue;
				}
				$content .= $record;
				$rows++;
			}
		}
		return [$content, $rows, $partial, $skipped, $truncatedCells, $fileAnswersOmitted, $answersTruncated, $rowOrByteBoundReached];
	}

	/** @param list<string> $cells */
	private function line(array $cells): string {
		$escaped = [];
		foreach ($cells as $cell) {
			if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $cell) === 1) {
				$cell = "'" . $cell;
			}
			$escaped[] = '"' . str_replace('"', '""', $cell) . '"';
		}
		return implode(',', $escaped) . "\r\n";
	}
}
