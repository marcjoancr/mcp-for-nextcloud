<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Credential;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Forms responses are available only to callers with both the MCP grant and Forms' native results permission. */
#[Group('forms')]
final class FormsResponsesTest extends IntegrationTestCase {
	private const FORMS_API = Http::BASE . '/ocs/v2.php/apps/forms/api/v3/forms';

	/** @var array<string, array<string, string>> */
	private array $policySnapshot = [];

	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_FORMS') !== '1') {
			$this->markTestSkipped('Pinned Forms app is provisioned only in the opt-in Mail cell');
		}
		$this->snapshotPolicy(Fixtures::ALICE, ['grant_forms_read', 'grant_forms_read_responses']);
		$this->snapshotPolicy(Fixtures::BOB, ['grant_forms_read', 'grant_forms_read_responses']);
	}

	protected function tearDown(): void {
		try {
			$config = Server::get(IConfig::class);
			foreach ($this->policySnapshot as $uid => $values) {
				foreach ($values as $key => $value) {
					if ($value === '__mcp_it_missing__') {
						$config->deleteUserValue($uid, 'mcp', $key);
					} else {
						$config->setUserValue($uid, 'mcp', $key, $value);
					}
				}
			}
		} finally {
			parent::tearDown();
		}
	}

	public function testResultsReaderSeesPaginatedAnswersButSubmitOnlyForeignAndRevokedAccessDoNot(): void {
		$alice = LoginFactory::basic(Fixtures::ALICE);
		$bob = LoginFactory::basic(Fixtures::BOB);
		$formId = $this->createForm($alice);
		try {
			$question = $this->request($alice, 'POST', '/' . $formId . '/questions', ['type' => 'short', 'text' => 'Response question']);
			$questionId = (int)$question['id'];
			$this->request($alice, 'PATCH', '/' . $formId, ['keyValuePairs' => ['submitMultiple' => true]]);
			$resultsShare = $this->request($alice, 'POST', '/' . $formId . '/shares', [
				'shareType' => 0, 'shareWith' => Fixtures::BOB, 'permissions' => ['submit', 'results'],
			]);
			$resultsShareId = (int)($resultsShare['id'] ?? 0);
			self::assertGreaterThan(0, $resultsShareId, 'native results share was created');
			$this->request($bob, 'POST', '/' . $formId . '/submissions', [
				'answers' => [(string)$questionId => ['first private answer']],
			]);
			$this->request($bob, 'POST', '/' . $formId . '/submissions', [
				'answers' => [(string)$questionId => ['second private answer']],
			]);

			$policy = Server::get(GrantPolicy::class);
			$policy->setGlobalEnabled(true);
			foreach ([Fixtures::ALICE, Fixtures::BOB] as $uid) {
				$policy->setEligible($uid, true);
				$policy->setConnected($uid, true);
				$policy->setGrant($uid, 'forms', 'read', true);
			}
			$aliceClient = new McpClient($alice);
			$bobClient = new McpClient($bob);
			$policy->setGrant(Fixtures::ALICE, 'forms', 'read_responses', true);
			$policy->setGrant(Fixtures::BOB, 'forms', 'read_responses', true);

			$first = $aliceClient->tool('forms_read_responses', ['id' => $formId, 'limit' => 1]);
			self::assertTrue($first->ok(), $first->describe());
			$firstData = $first->structured() ?: $first->json();
			self::assertSame($formId, $firstData['formId']);
			self::assertFalse($firstData['isAnonymous']);
			self::assertCount(1, $firstData['submissions']);
			self::assertTrue($firstData['hasMore']);
			self::assertSame(1, $firstData['nextOffset']);
			self::assertSame($questionId, $firstData['submissions'][0]['answers'][0]['questionId']);
			self::assertEqualsCanonicalizing(['questionId', 'text'], array_keys($firstData['submissions'][0]['answers'][0]));
			self::assertContains($firstData['submissions'][0]['answers'][0]['text'], ['first private answer', 'second private answer']);
			self::assertSame(Fixtures::BOB, $firstData['submissions'][0]['userId']);
			self::assertArrayNotHasKey('hash', $firstData['submissions'][0]);
			self::assertArrayNotHasKey('shareHash', $firstData['submissions'][0]);
			self::assertArrayNotHasKey('fileId', $firstData['submissions'][0]);

			$resultReader = $bobClient->tool('forms_read_responses', ['id' => $formId]);
			self::assertTrue($resultReader->ok(), $resultReader->describe());
			$readerData = $resultReader->structured() ?: $resultReader->json();
			self::assertCount(2, $readerData['submissions'], 'an explicit Forms results share can read all submissions');

			$second = $aliceClient->tool('forms_read_responses', ['id' => $formId, 'offset' => 1, 'limit' => 1]);
			self::assertTrue($second->ok(), $second->describe());
			$secondData = $second->structured() ?: $second->json();
			self::assertCount(1, $secondData['submissions']);
			self::assertFalse($secondData['hasMore']);
			self::assertNull($secondData['nextOffset']);

			$this->request($alice, 'PATCH', '/' . $formId . '/shares/' . $resultsShareId, [
				'keyValuePairs' => ['permissions' => ['submit']],
			]);
			$submitOnlyRead = $bobClient->tool('forms_read_responses', ['id' => $formId]);
			self::assertTrue($submitOnlyRead->isError(), 'Forms submit permission alone never grants answer access');

			$foreignFormId = $this->createForm($alice);
			try {
				$foreign = $bobClient->tool('forms_read_responses', ['id' => $foreignFormId]);
				self::assertTrue($foreign->isError(), 'a form with no native share is not visible to this reader');
			} finally {
				$this->request($alice, 'DELETE', '/' . $foreignFormId, null);
			}
			$missing = $aliceClient->tool('forms_read_responses', ['id' => 2147000000]);
			self::assertTrue($missing->isError());
			self::assertSame($missing->fingerprint(), $foreign->fingerprint(),
				'foreign and missing forms use the same generic response');

			$policy->setGrant(Fixtures::ALICE, 'forms', 'read_responses', false);
			$revoked = $aliceClient->tool('forms_read_responses', ['id' => $formId]);
			self::assertSame(-32602, $revoked->body['error']['code'] ?? null,
				'revoking the MCP permission removes the tool from this user’s available catalog');
			self::assertSame('Unknown tool', $revoked->body['error']['message'] ?? null);
		} finally {
			$this->request($alice, 'DELETE', '/' . $formId, null);
		}
	}

	public function testAnonymousFormResponsesDoNotExposeOrPersistTheSubmitterIdentity(): void {
		$alice = LoginFactory::basic(Fixtures::ALICE);
		$bob = LoginFactory::basic(Fixtures::BOB);
		$formId = $this->createForm($alice);
		try {
			$this->request($alice, 'PATCH', '/' . $formId, ['keyValuePairs' => ['isAnonymous' => true]]);
			$question = $this->request($alice, 'POST', '/' . $formId . '/questions', ['type' => 'short', 'text' => 'Anonymous answer']);
			$this->request($alice, 'POST', '/' . $formId . '/shares', [
				'shareType' => 0, 'shareWith' => Fixtures::BOB, 'permissions' => ['submit'],
			]);
			$this->request($bob, 'POST', '/' . $formId . '/submissions', [
				'answers' => [(string)$question['id'] => ['anonymous answer canary']],
			]);
			$nativeForm = $this->request($alice, 'GET', '/' . $formId, null);
			self::assertTrue($nativeForm['isAnonymous'] ?? false, 'the fixture is anonymous in the native Forms app');

			$policy = Server::get(GrantPolicy::class);
			$policy->setGlobalEnabled(true);
			$policy->setEligible(Fixtures::ALICE, true);
			$policy->setConnected(Fixtures::ALICE, true);
			$policy->setGrant(Fixtures::ALICE, 'forms', 'read', true);
			$policy->setGrant(Fixtures::ALICE, 'forms', 'read_responses', true);
			$reply = (new McpClient($alice))->tool('forms_read_responses', ['id' => $formId]);
			self::assertTrue($reply->ok(), $reply->describe());
			$data = $reply->structured() ?: $reply->json();
			self::assertCount(1, $data['submissions']);
			self::assertArrayNotHasKey('userId', $data['submissions'][0], 'anonymous responses omit respondent identifiers');
			self::assertSame('anonymous answer canary', $data['submissions'][0]['answers'][0]['text']);

			$nativeSubmission = $this->request($alice, 'GET', '/' . $formId . '/submissions/' . $data['submissions'][0]['id'], null);
			self::assertStringStartsWith('anon-user-', (string)($nativeSubmission['userId'] ?? ''),
				'Forms stores its anonymous actor key instead of the submitter account ID');

			// Historical anonymous records must remain anonymous if the owner later changes the form setting.
			$this->request($alice, 'PATCH', '/' . $formId, ['keyValuePairs' => ['isAnonymous' => false]]);
			$this->request($bob, 'POST', '/' . $formId . '/submissions', [
				'answers' => [(string)$question['id'] => ['identified answer after anonymity was disabled']],
			]);
			$afterToggle = (new McpClient($alice))->tool('forms_read_responses', ['id' => $formId]);
			self::assertTrue($afterToggle->ok(), $afterToggle->describe());
			$afterToggleData = $afterToggle->structured() ?: $afterToggle->json();
			self::assertFalse($afterToggleData['isAnonymous']);
			$byAnswer = [];
			foreach ($afterToggleData['submissions'] as $submission) {
				$byAnswer[$submission['answers'][0]['text']] = $submission;
			}
			self::assertArrayNotHasKey('userId', $byAnswer['anonymous answer canary'],
				'a prior anonymous submission stays redacted after the form setting changes');
			self::assertSame(Fixtures::BOB, $byAnswer['identified answer after anonymity was disabled']['userId'] ?? null,
				'new submissions after disabling anonymity can retain their respondent identity');
		} finally {
			$this->request($alice, 'DELETE', '/' . $formId, null);
		}
	}

	private function snapshotPolicy(string $uid, array $keys): void {
		$config = Server::get(IConfig::class);
		foreach ($keys as $key) {
			$this->policySnapshot[$uid][$key] = $config->getUserValue($uid, 'mcp', $key, '__mcp_it_missing__');
		}
	}

	private function createForm(Credential $credential): int {
		$form = $this->request($credential, 'POST', '', null);
		$id = (int)($form['id'] ?? 0);
		self::assertGreaterThan(0, $id, 'Forms API returned no form id');
		return $id;
	}

	/** @return array<string, mixed> */
	private function request(Credential $credential, string $method, string $path, ?array $body): array {
		$headers = [
			'Authorization: ' . $credential->authorization(),
			'OCS-APIRequest: true',
			'Accept: application/json',
			'Content-Type: application/json',
		];
		$url = self::FORMS_API . $path . '?format=json';
		$response = Http::request($method, $url, $headers, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
		self::assertContains($response['status'], [200, 201], substr($response['body'], 0, 600));
		$decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
		self::assertContains((int)($decoded['ocs']['meta']['statuscode'] ?? 0), [200, 201], substr($response['body'], 0, 600));
		$data = $decoded['ocs']['data'] ?? [];
		return is_array($data) ? $data : ['value' => $data];
	}
}
