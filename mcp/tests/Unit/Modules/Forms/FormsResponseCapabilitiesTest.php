<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Contracts\IncomingFileWriter;
use OCA\Mcp\Modules\Forms\FormsResponseReader;
use OCA\Mcp\Modules\Forms\Responses\FormsResponseExport;
use OCA\Mcp\Modules\Forms\Responses\FormsResponseSummary;
use OCA\Mcp\Modules\Forms\Presentation\FormsPlanRenderer;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class FormsResponseCapabilitiesTest extends TestCase {
	public function testSummaryAggregatesDeterministicallyAndOmitsIdentitiesAndFileAnswers(): void {
		$reader = new CapabilityResponseReader($this->page());
		$result = (new FormsResponseSummary($reader))->summarize('alice', ['id' => 7]);

		self::assertSame(3, $result['submissionsAnalyzed']);
		self::assertSame([
				['questionId' => 3, 'question' => 'Color?', 'type' => 'radio', 'answers' => [
					['answer' => 'blue', 'count' => 2], ['answer' => 'red', 'count' => 1],
			], 'answerCount' => 3, 'skippedAnswers' => 0],
			['questionId' => 4, 'question' => 'Comment', 'type' => 'short', 'answers' => [], 'answerCount' => 0, 'skippedAnswers' => 0],
		], $result['questions']);
		self::assertTrue($result['fileAnswersOmitted']);
		self::assertStringNotContainsString('alice', json_encode($result, JSON_THROW_ON_ERROR));
	}

	public function testSummaryMarksPartialWhenThereAreMoreSubmissionsAndEnforcesScanBounds(): void {
		$reader = new CapabilityResponseReader($this->page(101));
		$result = (new FormsResponseSummary($reader))->summarize('alice', ['id' => 7]);

		self::assertTrue($result['partial']);
		self::assertSame(50, $result['submissionsAnalyzed']);
		self::assertSame(50, $reader->requests[0][3]);
	}

	public function testExportPreviewIsFormulaSafeBoundedAndConfirmationRechecksState(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('secret', '')->willReturn('test-secret');
		$states = new PlanState($config);
		$reader = new CapabilityResponseReader($this->page());
		$files = new CapabilityIncomingFileWriter();
		$export = new FormsResponseExport($reader, $files, $states);

		$reader->page['submissions'][0]['answers'][0]['text'] = '=SUM(1,1)';
		$plan = $export->execute('alice', ['id' => 7, 'path' => 'exports/form.csv']);
		self::assertTrue($plan['requiresConfirmation']);
		self::assertSame('exports/form.csv', $plan['destination']['path']);
		self::assertArrayNotHasKey('content', $plan);
		self::assertTrue($plan['fileAnswersOmitted']);

		$files->saveResult = ['requiresConfirmation' => false, 'name' => 'form.csv'];
		$result = $export->execute('alice', ['id' => 7, 'path' => 'exports/form.csv', 'confirm' => true, PlanState::ARGUMENT => $plan['plan_state']]);
		self::assertSame(true, $result['exported']);
		self::assertSame(1, $files->saveCalls);
		self::assertStringContainsString("'=SUM(1,1)", $files->lastContent);

		$stalePlan = $export->execute('alice', ['id' => 7, 'path' => 'exports/form.csv']);
		$reader->page['submissions'][0]['answers'][0]['text'] = 'changed';
		try {
			$export->execute('alice', ['id' => 7, 'path' => 'exports/form.csv', 'confirm' => true, PlanState::ARGUMENT => $stalePlan['plan_state']]);
			self::fail('A changed response set must invalidate the approved export');
		} catch (PlanChanged $e) {
			self::assertSame(FormsResponseExport::MESSAGE, $e->plan['message']);
		}
	}

	public function testSummaryKeepsDistinctLongAnswersDistinctAndMarksTruncation(): void {
		$page = $this->page();
		$prefix = str_repeat('x', 1024);
		$page['submissions'] = [
			['id' => 1, 'answers' => [['questionId' => 3, 'text' => $prefix . 'a']]],
			['id' => 2, 'answers' => [['questionId' => 3, 'text' => $prefix . 'b']]],
		];
		$summary = (new FormsResponseSummary(new CapabilityResponseReader($page)))->summarize('alice', ['id' => 7]);
		self::assertTrue($summary['partial']);
		self::assertCount(2, $summary['questions'][0]['answers']);
		self::assertTrue($summary['questions'][0]['answers'][0]['answerTruncated']);
	}

	public function testSummaryMarksPerSubmissionAndAggregateScanBounds(): void {
		$page = $this->page();
		$page['submissions'] = [];
		for ($i = 0; $i < 101; $i++) {
			$answers = [];
			for ($j = 0; $j < 201; $j++) {
				$answers[] = ['questionId' => 3, 'text' => 'answer-' . $j];
			}
			$page['submissions'][] = ['id' => $i + 1, 'answers' => $answers];
		}
		$summary = (new FormsResponseSummary(new CapabilityResponseReader($page)))->summarize('alice', ['id' => 7, 'limit' => 100]);
		self::assertTrue($summary['partial']);
		self::assertSame(100, $summary['submissionsAnalyzed']);
		self::assertSame(FormsResponseSummary::MAX_SCAN_ANSWERS, $summary['questions'][0]['answerCount']);
		self::assertGreaterThan(0, $summary['skippedAnswersForBounds']);
	}

	public function testExportMarksByteAndTextTruncationAndProvidesContinuationOffset(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('test-secret');
		$states = new PlanState($config);
		$page = $this->page(101);
		$largeText = str_repeat('x', 2048);
		foreach ($page['submissions'] as &$submission) {
			for ($i = 0; $i < 200; $i++) {
				$submission['answers'][] = ['questionId' => 3, 'text' => $largeText];
			}
		}
		unset($submission);
		$files = new CapabilityIncomingFileWriter();
		$plan = (new FormsResponseExport(new CapabilityResponseReader($page), $files, $states))->preview('alice', ['id' => 7, 'path' => 'form.csv']);
		self::assertTrue($plan['partial']);
		self::assertSame(50, $plan['nextOffset']);
		self::assertGreaterThan(0, $plan['truncatedCells']);
		self::assertLessThanOrEqual(FormsResponseExport::MAX_CSV_BYTES, $plan['destination']['size']);
		self::assertGreaterThan(0, $plan['skippedRows']);
	}

	public function testExportAccountsForAnswersWhoseQuestionMetadataWasTruncated(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('test-secret');
		$page = $this->page();
		$page['questionsTruncated'] = true;
		$page['submissions'] = [['id' => 1, 'answers' => [['questionId' => 999, 'text' => 'not silently omitted']]]];
		$plan = (new FormsResponseExport(new CapabilityResponseReader($page), new CapabilityIncomingFileWriter(), new PlanState($config)))
			->preview('alice', ['id' => 7, 'path' => 'form.csv']);
		self::assertTrue($plan['partial']);
		self::assertTrue($plan['questionsTruncated']);
		self::assertSame(1, $plan['skippedRows']);
		self::assertStringContainsString('Question metadata exceeded', (new FormsPlanRenderer())->render('forms_export_responses', $plan));
	}

	public function testExportPlanRendererShowsSourcePageAndEscapesDestinationWithoutPlanState(): void {
		$rendered = (new FormsPlanRenderer())->render('forms_export_responses', [
			'formId' => 7, 'offset' => 50, 'limit' => 50, 'rows' => 3, 'partial' => true, 'nextOffset' => 100,
			'destination' => ['path' => 'exports/[canary](https://invalid.test).csv'], 'plan_state' => 'opaque-secret-value',
		]);
		self::assertStringContainsString('Form ID: 7', $rendered);
		self::assertStringContainsString('offset 50, limit 50', $rendered);
		self::assertStringContainsString('offset 100', $rendered);
		self::assertStringNotContainsString('opaque-secret-value', $rendered);
	}

	public function testExportFailsClosedWhenNativeResultsPermissionDisappears(): void {
		$reader = new CapabilityResponseReader(null);
		$export = new FormsResponseExport($reader, new CapabilityIncomingFileWriter(), $this->states());
		try {
			$export->execute('alice', ['id' => 7, 'path' => 'form.csv']);
			self::fail('No native results access must prevent export');
		} catch (ToolFailure $e) {
			self::assertSame(ToolFailure::NOT_FOUND, $e->getMessage());
		}
	}

	public function testExportPlanCannotBeReplayedByAnotherUserAndPreviewNeverWrites(): void {
		$reader = new CapabilityResponseReader($this->page());
		$files = new CapabilityIncomingFileWriter();
		$export = new FormsResponseExport($reader, $files, $this->states());
		$plan = $export->execute('alice', ['id' => 7, 'path' => 'exports/form.csv']);
		self::assertSame(0, $files->saveCalls, 'preview does not write');
		try {
			$export->execute('bob', ['id' => 7, 'path' => 'exports/form.csv', 'confirm' => true, PlanState::ARGUMENT => $plan['plan_state']]);
			self::fail('A plan bound to Alice must not authorize a write for Bob');
		} catch (PlanChanged $e) {
			self::assertSame(0, $files->saveCalls, 'cross-user confirmation is rejected before Files write');
		}
	}

	private function states(): PlanState {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('test-secret');
		return new PlanState($config);
	}

	private function page(int $count = 3): array {
		$submissions = [];
		for ($i = 0; $i < $count; $i++) {
			$submissions[] = ['id' => $i + 1, 'timestamp' => 100 + $i, 'userId' => 'alice', 'answers' => [
				['questionId' => 3, 'text' => $i === 2 ? 'red' : 'blue'],
				['questionId' => 9, 'text' => '/private/path', 'fileId' => 88],
			]];
		}
		return ['formId' => 7, 'isAnonymous' => false, 'fileQuestionIds' => [9], 'questions' => [
			['id' => 3, 'text' => 'Color?', 'type' => 'radio'], ['id' => 4, 'text' => 'Comment', 'type' => 'short'], ['id' => 9, 'text' => 'Upload', 'type' => 'file'],
		], 'submissions' => $submissions];
	}
}

final class CapabilityResponseReader implements FormsResponseReader {
	public array $requests = [];
	public function __construct(public ?array $page) {}
	public function readResponses(string $userId, int $formId, int $offset, int $limit): ?array {
		$this->requests[] = [$userId, $formId, $offset, $limit];
		if ($this->page === null) { return null; }
		$page = $this->page;
		$page['submissions'] = array_slice($page['submissions'], $offset, $limit + 1);
		return $page;
	}
}

final class CapabilityIncomingFileWriter implements IncomingFileWriter {
	public string $lastContent = '';
	public int $saveCalls = 0;
	public array $saveResult = [];
	public function preview(string $userId, string $path, int $size): array { return ['path' => $path, 'size' => $size, 'shared' => false]; }
	public function save(string $userId, string $path, string $content, bool $confirmShared): array {
		$this->lastContent = $content;
		$this->saveCalls++;
		return $this->saveResult;
	}
}
