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
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Native Forms API fixtures and MCP calls against a pinned Forms app in the opt-in Mail cell. */
#[Group('forms')]
final class McpFormsIsolationTest extends IntegrationTestCase {
	private array $toolPermissionSnapshot = [];
	private const FORMS_API = Http::BASE . '/ocs/v2.php/apps/forms/api/v3/forms';

	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_FORMS') !== '1') {
			$this->markTestSkipped('Pinned Forms app is provisioned only in the opt-in Mail cell');
		}
		$this->toolPermissionSnapshot = \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::capture([Fixtures::ALICE, Fixtures::BOB], ['forms_list', 'forms_read']);
		foreach ([Fixtures::ALICE, Fixtures::BOB] as $uid) {
			\OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign($uid, ['forms_list' => 'allow', 'forms_read' => 'allow']);
		}
	}

	protected function tearDown(): void {
		try {
			if ($this->toolPermissionSnapshot !== []) { \OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::restore($this->toolPermissionSnapshot); }
		} finally {
			parent::tearDown();
		}
	}

	public function testListAndReadStayWithinOwnedAndSharedFormsAndExposeOnlySchema(): void {
		$alice = LoginFactory::basic(Fixtures::ALICE);
		$bob = LoginFactory::basic(Fixtures::BOB);
		$aliceForm = $this->createForm($alice);
		$bobForm = $this->createForm($bob);
		try {
			$question = $this->request($alice, 'POST', '/' . $aliceForm . '/questions', ['type' => 'short', 'text' => 'Schema question']);
			self::assertSame('Schema question', $question['text']);
			$choice = $this->request($alice, 'POST', '/' . $aliceForm . '/questions', ['type' => 'multiple_unique', 'text' => 'Schema choice']);
			$options = $this->request($alice, 'POST', '/' . $aliceForm . '/questions/' . $choice['id'] . '/options', [
				'optionTexts' => ['Option Canary'],
			]);
			self::assertGreaterThan(0, (int)$options[0]['id'], 'Forms API returned no option id');
			$this->request($alice, 'POST', '/' . $aliceForm . '/shares', [
				'shareType' => 0, 'shareWith' => Fixtures::BOB, 'permissions' => ['submit'],
			]);
			$this->request($bob, 'POST', '/' . $aliceForm . '/submissions', [
				'answers' => [(string)$question['id'] => ['private-response-canary']],
			]);

			$policy = Server::get(GrantPolicy::class);
			$policy->setGlobalEnabled(true);
			foreach ([Fixtures::ALICE, Fixtures::BOB] as $uid) {
				$policy->setEligible($uid, true);
				$policy->setConnected($uid, true);
				\OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture::assign($uid, ['forms_list' => 'allow', 'forms_read' => 'allow']);
			}
			$aliceClient = new McpClient($alice);
			$bobClient = new McpClient($bob);
			$aliceList = $aliceClient->tool('forms_list');
			$bobList = $bobClient->tool('forms_list');
			self::assertTrue($aliceList->ok(), $aliceList->describe());
			self::assertTrue($bobList->ok(), $bobList->describe());
			$aliceForms = $aliceList->json()['forms'];
			$bobForms = $bobList->json()['forms'];
			self::assertContains($aliceForm, array_column($aliceForms, 'id'));
			self::assertNotContains($bobForm, array_column($aliceForms, 'id'));
			self::assertContains($aliceForm, array_column($bobForms, 'id'), 'Bob has an explicit submit share');
			self::assertContains($bobForm, array_column($bobForms, 'id'));

			$aliceRead = $aliceClient->tool('forms_read', ['id' => $aliceForm]);
			$bobSharedRead = $bobClient->tool('forms_read', ['id' => $aliceForm]);
			$aliceForeignRead = $aliceClient->tool('forms_read', ['id' => $bobForm]);
			self::assertTrue($aliceRead->ok(), $aliceRead->describe());
			self::assertTrue($bobSharedRead->ok(), $bobSharedRead->describe());
			self::assertTrue($aliceForeignRead->isError());
			$schema = $aliceRead->json();
			$sharedSchema = $bobSharedRead->json();
			self::assertSame(['submit'], $sharedSchema['permissions']);
			self::assertContains('edit', $schema['permissions']);
			unset($schema['permissions'], $sharedSchema['permissions']);
			self::assertSame($schema, $sharedSchema, 'Shared user sees the same form schema, with only their native permissions');
			self::assertSame('Schema question', $schema['questions'][0]['text']);
			$choiceQuestions = array_values(array_filter($schema['questions'], static fn (array $item): bool => $item['text'] === 'Schema choice'));
			self::assertCount(1, $choiceQuestions);
			$choiceSchema = $choiceQuestions[0];
			self::assertSame('Option Canary', $choiceSchema['options'][0]['text']);
			self::assertGreaterThan(0, (int)$choiceSchema['options'][0]['id']);
			foreach (['hash', 'shares', 'submissionCount', 'submissions', 'answers', 'fileId'] as $privateField) {
				self::assertArrayNotHasKey($privateField, $schema);
			}
			self::assertStringNotContainsString('private-response-canary', $aliceRead->text());
			self::assertStringNotContainsString('private-response-canary', $bobSharedRead->text());
		} finally {
			$this->deleteForm($alice, $aliceForm);
			$this->deleteForm($bob, $bobForm);
		}
	}

	private function createForm(Credential $credential): int {
		$form = $this->request($credential, 'POST', '', null);
		$id = (int)($form['id'] ?? 0);
		self::assertGreaterThan(0, $id, 'Forms API returned no form id');
		return $id;
	}

	private function deleteForm(Credential $credential, int $id): void {
		$this->request($credential, 'DELETE', '/' . $id, null);
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
