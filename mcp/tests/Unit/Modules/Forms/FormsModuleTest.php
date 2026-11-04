<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Modules\Forms\FormsModule;
use OCA\Mcp\Modules\Forms\FormsReader;
use PHPUnit\Framework\TestCase;

final class FormsModuleTest extends TestCase {
    public function testListsOnlyCurrentUsersVisibleFormsAndReturnsSafeSummaries(): void {
        $reader = new FakeFormsReader([
            'alice' => [
                ['id' => 1, 'title' => 'Owned', 'permissions' => ['edit', 'submit', 'results'], 'partial' => true, 'submissionCount' => 42],
                ['id' => 2, 'title' => 'Shared', 'permissions' => ['submit'], 'partial' => true],
            ],
            'bob' => [['id' => 3, 'title' => 'Bob only', 'permissions' => ['submit']]],
        ], []);
        $module = FormsModule::withReader($reader);

        $result = $this->call($module, 'forms_list', [], 'alice');

        self::assertSame([
            'forms' => [
                ['id' => 1, 'title' => 'Owned', 'permissions' => ['edit', 'submit', 'results']],
                ['id' => 2, 'title' => 'Shared', 'permissions' => ['submit']],
            ],
            'hasMore' => false,
            'nextOffset' => null,
        ], $result);
        self::assertSame(['alice'], $reader->listedUsers);
    }

    public function testReadReturnsFormStructureWithoutResultsOrShareSecrets(): void {
        $reader = new FakeFormsReader([], [
            'alice:7' => [
                'id' => 7,
                'title' => 'Survey',
                'description' => 'Questionnaire',
                'ownerId' => 'alice',
                'permissions' => ['edit', 'results', 'submit'],
                'submissionCount' => 12,
                'hash' => 'private-form-hash',
                'fileId' => 999,
                'shares' => [['token' => 'secret-share-token']],
                'questions' => [[
                    'id' => 11,
                    'text' => 'Color?',
                    'type' => 'radio',
                    'isRequired' => true,
                    'answerKey' => 'private-answer-key',
                    'extraSettings' => ['optionsLimitMin' => 1, 'correctAnswer' => 'Blue'],
                    'options' => [['id' => 21, 'text' => 'Blue', 'correct' => true, 'fileId' => 987]],
                ]],
            ],
        ]);
        $module = FormsModule::withReader($reader);

        $result = $this->call($module, 'forms_read', ['id' => 7], 'alice');

        self::assertSame('Survey', $result['title']);
        self::assertSame(['id' => 11, 'type' => 'radio', 'isRequired' => true, 'text' => 'Color?', 'optionsTotal' => 1, 'optionsOffset' => 0, 'optionsLimit' => 50, 'optionsHasMore' => false, 'nextOptionOffset' => null, 'options' => [['id' => 21, 'text' => 'Blue']], 'extraSettings' => ['optionsLimitMin' => 1]], $result['questions'][0]);
        self::assertArrayNotHasKey('submissionCount', $result);
        self::assertArrayNotHasKey('shares', $result);
        self::assertArrayNotHasKey('hash', $result);
        self::assertArrayNotHasKey('fileId', $result);
        self::assertSame(['edit', 'results', 'submit'], $result['permissions']);
        self::assertSame([['alice', 7]], $reader->readKeys);
    }

    public function testListPagesLargeFormSetsAndReportsTheNextOffset(): void {
        $summaries = [];
        for ($id = 1; $id <= 4; $id++) {
            $summaries[] = ['id' => $id, 'title' => 'Form ' . $id, 'permissions' => ['submit']];
        }
        $module = FormsModule::withReader(new FakeFormsReader(['alice' => $summaries], []));

        $result = $this->call($module, 'forms_list', ['limit' => 2, 'offset' => 1], 'alice');

        self::assertSame([2, 3], array_column($result['forms'], 'id'));
        self::assertTrue($result['hasMore']);
        self::assertSame(3, $result['nextOffset']);
    }

    public function testReadBoundsQuestionsOptionsAndExtraSettingsAndMarksTruncation(): void {
        $options = [];
        for ($id = 1; $id <= 101; $id++) {
            $options[] = ['id' => $id, 'text' => 'Option ' . $id];
        }
        $questions = [];
        for ($id = 1; $id <= 101; $id++) {
            $questions[] = ['id' => $id, 'text' => 'Question', 'type' => 'multiple', 'options' => $options, 'extraSettings' => ['rows' => range(1, 101)]];
        }
        $reader = new FakeFormsReader([], ['alice:9' => ['id' => 9, 'title' => 'Large', 'questions' => $questions]]);
        $module = FormsModule::withReader($reader);

        $result = $this->call($module, 'forms_read', ['id' => 9], 'alice');

        self::assertCount(50, $result['questions']);
        self::assertTrue($result['questionsHasMore']);
        self::assertSame(50, $result['nextQuestionOffset']);
        self::assertCount(50, $result['questions'][0]['options']);
        self::assertTrue($result['questions'][0]['optionsHasMore']);
        self::assertSame(50, $result['questions'][0]['nextOptionOffset']);
        self::assertCount(100, $result['questions'][0]['extraSettings']['rows']);
        self::assertTrue($result['questions'][0]['extraSettingsTruncated']);
    }

    public function testReadPagesTheRestOfQuestionsAndOptionsOnDemand(): void {
        $options = [];
        for ($id = 1; $id <= 5; $id++) {
            $options[] = ['id' => $id, 'text' => 'Option ' . $id];
        }
        $questions = [];
        for ($id = 1; $id <= 5; $id++) {
            $questions[] = ['id' => $id, 'text' => 'Question ' . $id, 'type' => 'multiple', 'options' => $options];
        }
        $module = FormsModule::withReader(new FakeFormsReader([], ['alice:9' => ['id' => 9, 'title' => 'Large', 'questions' => $questions]]));

        $result = $this->call($module, 'forms_read', ['id' => 9, 'question_offset' => 2, 'question_limit' => 2, 'option_offset' => 1, 'option_limit' => 2], 'alice');

        self::assertSame([3, 4], array_column($result['questions'], 'id'));
        self::assertSame(5, $result['questionsTotal']);
        self::assertSame(4, $result['nextQuestionOffset']);
        self::assertSame([2, 3], array_column($result['questions'][0]['options'], 'id'));
        self::assertSame(5, $result['questions'][0]['optionsTotal']);
        self::assertSame(3, $result['questions'][0]['nextOptionOffset']);
    }

    public function testReadBoundsLongTextAndMarksTruncatedFields(): void {
        $longText = str_repeat('x', 2048);
        $module = FormsModule::withReader(new FakeFormsReader([], [
            'alice:10' => [
                'id' => 10,
                'title' => $longText,
                'questions' => [[
                    'id' => 1,
                    'text' => $longText,
                    'options' => [['id' => 2, 'text' => $longText]],
                    'extraSettings' => ['rows' => [$longText]],
                ]],
            ],
        ]));

        $result = $this->call($module, 'forms_read', ['id' => 10], 'alice');

        self::assertSame(1024, strlen($result['title']));
        self::assertTrue($result['titleTruncated']);
        self::assertSame(1024, strlen($result['questions'][0]['text']));
        self::assertTrue($result['questions'][0]['textTruncated']);
        self::assertSame(1024, strlen($result['questions'][0]['options'][0]['text']));
        self::assertTrue($result['questions'][0]['options'][0]['textTruncated']);
        self::assertSame(1024, strlen($result['questions'][0]['extraSettings']['rows'][0]));
        self::assertTrue($result['questions'][0]['extraSettingsTruncated']);
    }

    public function testInaccessibleFormIsReportedAsNotFound(): void {
        $module = FormsModule::withReader(new FakeFormsReader([], []));

        try {
            $this->call($module, 'forms_read', ['id' => 77], 'alice');
            self::fail('An inaccessible form must not be revealed');
        } catch (ToolFailure $e) {
            self::assertSame(ToolFailure::NOT_FOUND, $e->getMessage());
        }
    }

    public function testCatalogContainsOnlyReadTools(): void {
        $module = FormsModule::withReader(new FakeFormsReader([], []));
        $definitions = $module->definitions();

        self::assertSame(['forms_list', 'forms_read', 'forms_read_responses'], array_column($definitions, 'name'));
        self::assertSame(['read', 'read', 'read_responses'], array_column($definitions, 'operation'));
        self::assertSame(['forms', 'forms', 'forms'], array_column($definitions, 'app'));
    }

    public function testReadsOnlyAllowlistedResponseFieldsAndHonorsAnonymousForms(): void {
        $reader = new FakeFormsReader([], []);
        $responses = new FakeFormsResponseReader(['alice:7' => [
            'formId' => 7,
            'isAnonymous' => true,
            'submissions' => [[
                'id' => 1, 'formId' => 7, 'userId' => 'alice', 'timestamp' => 123,
                'answers' => [['id' => 2, 'submissionId' => 1, 'fileId' => 999, 'questionId' => 3, 'text' => 'yes', 'uploadedFileToken' => 'secret']],
            ]],
        ]]);
        $module = FormsModule::withReader($reader, $responses);

        $result = $this->call($module, 'forms_read_responses', ['id' => 7], 'alice');

        self::assertSame([['id' => 1, 'timestamp' => 123, 'answers' => [['questionId' => 3, 'text' => 'yes']], 'answersTruncated' => false]], $result['submissions']);
        self::assertSame(7, $result['formId']);
        self::assertTrue($result['isAnonymous']);
        self::assertFalse($result['fileAnswersOmitted']);
        self::assertSame(['alice', 7, 0, 50], $responses->requests[0]);
    }

    public function testHistoricalAnonymousResponsesKeepTheirIdentityHidden(): void {
        $responses = new FakeFormsResponseReader(['alice:7' => [
            'formId' => 7, 'isAnonymous' => false,
            'submissions' => [
                ['id' => 1, 'userId' => 'anon-user-stable-pseudonym', 'timestamp' => 123, 'answers' => []],
                ['id' => 2, 'userId' => 'bob', 'timestamp' => 124, 'answers' => []],
            ],
        ]]);
        $module = FormsModule::withReader(new FakeFormsReader([], []), $responses);
        $result = $this->call($module, 'forms_read_responses', ['id' => 7], 'alice');
        self::assertArrayNotHasKey('userId', $result['submissions'][0]);
        self::assertSame('bob', $result['submissions'][1]['userId']);
    }

    public function testResponsePagesAreBoundedAndMissingOrForbiddenIsGeneric(): void {
        $responses = new FakeFormsResponseReader(['alice:7' => ['formId' => 7, 'isAnonymous' => false, 'submissions' => []]]);
        $module = FormsModule::withReader(new FakeFormsReader([], []), $responses);
        $result = $this->call($module, 'forms_read_responses', ['id' => 7, 'offset' => 8, 'limit' => 2], 'alice');
        self::assertSame(['formId' => 7, 'isAnonymous' => false, 'submissions' => [], 'fileAnswersOmitted' => false, 'hasMore' => false, 'nextOffset' => null], $result);
        self::assertSame(['alice', 7, 8, 2], $responses->requests[0]);

        try {
            $this->call($module, 'forms_read_responses', ['id' => 999], 'alice');
            self::fail('Missing and forbidden forms must be indistinguishable');
        } catch (ToolFailure $e) {
            self::assertSame(ToolFailure::NOT_FOUND, $e->getMessage());
        }
    }

    public function testResponseSanitizerDropsFileAnswersAndBoundsText(): void {
        $module = FormsModule::withReader(new FakeFormsReader([], []), new FakeFormsResponseReader(['alice:7' => [
            'formId' => 7,
            'isAnonymous' => false,
            'fileQuestionIds' => [4],
            'submissions' => [[
                'id' => 3, 'formId' => 7, 'userId' => 'alice', 'timestamp' => 50,
                'answers' => [
                    ['questionId' => 4, 'text' => '/private/file/path', 'fileId' => 66],
                    ['questionId' => 5, 'text' => str_repeat('x', 2048), 'uploadedFileToken' => 'never-return'],
                ],
            ]],
        ]]));

        $result = $this->call($module, 'forms_read_responses', ['id' => 7], 'alice');

        self::assertSame('alice', $result['submissions'][0]['userId']);
        self::assertTrue($result['fileAnswersOmitted']);
        self::assertCount(1, $result['submissions'][0]['answers']);
        self::assertSame(1024, strlen($result['submissions'][0]['answers'][0]['text']));
        self::assertTrue($result['submissions'][0]['answers'][0]['textTruncated']);
        self::assertArrayNotHasKey('uploadedFileToken', $result['submissions'][0]['answers'][0]);
    }

    private function call(FormsModule $module, string $name, array $arguments, string $userId): array {
        $definition = array_column($module->definitions(), null, 'name')[$name];
        $published = WriteGate::publish($definition);
        $validated = ArgumentValidator::validate($published['inputSchema'], $arguments);
        $result = $module->call($name, $validated, $userId);
        return json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }
}

final class FakeFormsResponseReader implements \OCA\Mcp\Modules\Forms\FormsResponseReader {
    public array $requests = [];
    public function __construct(private array $byKey) {}
    public function readResponses(string $userId, int $formId, int $offset, int $limit): ?array {
        $this->requests[] = [$userId, $formId, $offset, $limit];
        return $this->byKey[$userId . ':' . $formId] ?? null;
    }
}

final class FakeFormsReader implements FormsReader {
    public array $listedUsers = [];
    public array $readKeys = [];

    public function __construct(private array $formsByUser, private array $formsByKey) {
    }

    public function list(string $userId): array {
        $this->listedUsers[] = $userId;
        return $this->formsByUser[$userId] ?? [];
    }

    public function read(string $userId, int $formId): ?array {
        $this->readKeys[] = [$userId, $formId];
        return $this->formsByKey[$userId . ':' . $formId] ?? null;
    }
}
