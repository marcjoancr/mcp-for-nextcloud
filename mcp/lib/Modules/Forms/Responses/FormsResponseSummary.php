<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Responses;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Forms\FormsOutput;
use OCA\Mcp\Modules\Forms\FormsResponseReader;

/** Deterministic, bounded aggregates over the current user's native Forms results view. */
final class FormsResponseSummary {
	public const MAX_SCAN_BYTES = 4_194_304;
	public const MAX_SCAN_ANSWERS = 8_000;

	public function __construct(private FormsResponseReader $reader) {}

	/** @param array<string,mixed> $arguments @return array<string,mixed> */
	public function summarize(string $userId, array $arguments): array {
		$offset = max(0, (int)($arguments['offset'] ?? 0));
		$limit = min(max(1, (int)($arguments['limit'] ?? FormsOutput::DEFAULT_SUBMISSIONS)), FormsOutput::MAX_SUBMISSIONS);
		$page = $this->reader->readResponses($userId, (int)$arguments['id'], $offset, $limit);
		if ($page === null) {
			throw new ToolFailure(ToolFailure::NOT_FOUND);
		}

		$submissions = array_slice($page['submissions'], 0, $limit);
		$more = count($page['submissions']) > $limit;
		$questions = [];
		$questionMetadata = [];
		foreach (array_slice($page['questions'] ?? [], 0, FormsOutput::MAX_QUESTIONS) as $question) {
			if (!is_array($question) || !is_int($question['id'] ?? null)) {
				continue;
			}
			if (($question['type'] ?? null) === 'file') {
				continue;
			}
			$id = $question['id'];
			$questions[$id] = ['counts' => [], 'answerCount' => 0, 'skippedAnswers' => 0];
			$metadata = ['questionId' => $id];
			if (is_string($question['text'] ?? null)) {
				$metadata['question'] = self::bounded($question['text']);
				if (strlen($question['text']) > 1024) {
					$metadata['questionTruncated'] = true;
				}
			}
			if (is_string($question['type'] ?? null)) {
				$metadata['type'] = self::bounded($question['type']);
			}
			$questionMetadata[$id] = $metadata;
		}

		$fileIds = $page['fileQuestionIds'] ?? [];
		$fileAnswersOmitted = false;
		$questionsTruncated = ($page['questionsTruncated'] ?? false) || count($page['questions'] ?? []) > FormsOutput::MAX_QUESTIONS;
		$partial = $more || $questionsTruncated;
		foreach ($questionMetadata as $metadata) {
			$partial = $partial || (($metadata['questionTruncated'] ?? false) === true);
		}
		$partial = $partial || $questionsTruncated;
		$scannedAnswers = 0;
		$scannedBytes = 0;
		$skippedForBounds = 0;
		foreach ($submissions as $submission) {
			if (!is_array($submission) || !is_array($submission['answers'] ?? null)) {
				continue;
			}
			$answerList = $submission['answers'];
			if (count($answerList) > FormsOutput::MAX_ANSWERS_PER_SUBMISSION) {
				$partial = true;
				$skippedForBounds += count($answerList) - FormsOutput::MAX_ANSWERS_PER_SUBMISSION;
				foreach (array_slice($answerList, FormsOutput::MAX_ANSWERS_PER_SUBMISSION) as $omitted) {
					if (is_array($omitted) && is_int($omitted['questionId'] ?? null) && isset($questions[$omitted['questionId']])) {
						$questions[$omitted['questionId']]['skippedAnswers']++;
					}
				}
			}
			foreach (array_slice($answerList, 0, FormsOutput::MAX_ANSWERS_PER_SUBMISSION) as $answer) {
				if (!is_array($answer)) {
					continue;
				}
				$qid = $answer['questionId'] ?? null;
				if (is_int($qid) && in_array($qid, $fileIds, true)) {
					$fileAnswersOmitted = true;
					continue;
				}
				if (!is_int($qid) || !isset($questions[$qid]) || !is_string($answer['text'] ?? null) || $answer['text'] === '') {
					continue;
				}
				$text = $answer['text'];
				$size = strlen($text);
				if ($scannedAnswers >= self::MAX_SCAN_ANSWERS || $scannedBytes + $size > self::MAX_SCAN_BYTES) {
					$partial = true;
					$skippedForBounds++;
					$questions[$qid]['skippedAnswers']++;
					continue;
				}
				$scannedAnswers++;
				$scannedBytes += $size;
				if ($size > 1024) {
					$partial = true;
				}
				$value = self::bounded($text);
				$key = hash('sha256', $text);
				$questions[$qid]['counts'][$key] ??= ['answer' => $value, 'count' => 0, 'sortKey' => $key];
				if ($size > 1024) {
					$questions[$qid]['counts'][$key]['answerTruncated'] = true;
				}
				$questions[$qid]['counts'][$key]['count']++;
				$questions[$qid]['answerCount']++;
			}
		}

		$aggregate = [];
		foreach ($questions as $id => $item) {
			$counts = array_values($item['counts']);
			usort($counts, static fn(array $a, array $b): int => strcmp($a['answer'], $b['answer']) ?: strcmp($a['sortKey'], $b['sortKey']));
			$answers = array_map(static function (array $answer): array {
				unset($answer['sortKey']);
				return $answer;
			}, $counts);
			$aggregate[] = $questionMetadata[$id] + [
				'answers' => $answers, 'answerCount' => $item['answerCount'], 'skippedAnswers' => $item['skippedAnswers'],
			];
		}

		return [
			'formId' => (int)$page['formId'], 'isAnonymous' => (bool)$page['isAnonymous'],
			'submissionsAnalyzed' => count($submissions), 'offset' => $offset, 'limit' => $limit,
			'questions' => $aggregate, 'fileAnswersOmitted' => $fileAnswersOmitted,
			'questionsTruncated' => $questionsTruncated,
			'partial' => $partial, 'hasMore' => $more,
			'nextOffset' => $more ? $offset + count($submissions) : null,
			'skippedAnswersForBounds' => $skippedForBounds,
		];
	}

	public static function bounded(string $value): string {
		return strlen($value) > 1024 ? mb_strcut($value, 0, 1024, 'UTF-8') : $value;
	}
}
