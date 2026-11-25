<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\OfficialApps;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Credential;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

#[Group('official-apps')]
final class FormsOfficialOcsModeTest extends IntegrationTestCase {
	private const FORMS_API = Http::BASE . '/ocs/v2.php/apps/forms/api/v3/forms';

	public function testOfficialOcsUsesBasicButMcpBearerKeepsNativeReadsAndResultsPermission(): void {
		if (getenv('MCP_IT_WITH_FORMS') !== '1' || getenv('MCP_IT_MAIL_GUARD') !== '0') {
			$this->markTestSkipped('Requires the disposable stock Forms profile (Forms enabled, Mail guard disabled).');
		}

		$config = Server::get(IConfig::class);
		$setting = 'app.mcp.official-apps.forms';
		$previousOfficial = $config->getSystemValue($setting, '__mcp_it_missing__');
		$policy = Server::get(GrantPolicy::class);
		$previousGlobal = $policy->globalEnabled();
		$previousUsers = [];
		foreach ([Fixtures::ALICE, Fixtures::BOB] as $uid) {
			$previousUsers[$uid] = ['eligible' => $policy->eligible($uid), 'connected' => $policy->connected($uid)];
		}
		$toolSnapshot = ToolPermissionFixture::capture([Fixtures::ALICE, Fixtures::BOB], ['forms_list', 'forms_read', 'forms_read_responses']);
		$formId = 0;
		$alice = null;

		try {
			$config->setSystemValue($setting, true);
			$policy->setGlobalEnabled(true);
			foreach ([Fixtures::ALICE, Fixtures::BOB] as $uid) {
				$policy->setEligible($uid, true);
				$policy->setConnected($uid, true);
				ToolPermissionFixture::assign($uid, [
					'forms_list' => 'allow', 'forms_read' => 'allow', 'forms_read_responses' => 'allow',
				]);
			}
			$alice = LoginFactory::basic(Fixtures::ALICE);
			$bob = LoginFactory::basic(Fixtures::BOB);
			$formId = $this->createForm($alice);
			$question = $this->request($alice, 'POST', '/' . $formId . '/questions', ['type' => 'short', 'text' => 'OCS reader question']);
			$questionId = (int)$question['id'];
			$share = $this->request($alice, 'POST', '/' . $formId . '/shares', [
				'shareType' => 0, 'shareWith' => Fixtures::BOB, 'permissions' => ['submit'],
			]);
			$shareId = (int)($share['id'] ?? 0);
			self::assertGreaterThan(0, $shareId, 'submit-only Forms share was created');
			$this->request($bob, 'POST', '/' . $formId . '/submissions', [
				'answers' => [(string)$questionId => ['private OCS response canary']],
			]);
			$submitted = $this->request($bob, 'GET', '/' . $formId . '/submissions', null);
			self::assertCount(1, $submitted['submissions']);
			$submissionId = (int)($submitted['submissions'][0]['id'] ?? 0);
			self::assertGreaterThan(0, $submissionId, 'the ordinary user submitted a response');
			$own = $this->request($bob, 'GET', '/' . $formId . '/submissions/' . $submissionId, null);
			self::assertSame('private OCS response canary', $own['answers'][0]['text'] ?? null,
				'the official Forms v3 endpoint permits a submitter to read their own submission');

			$basic = new McpClient($bob);
			$listed = $basic->tool('forms_list');
			self::assertTrue($listed->ok(), $listed->describe());
			$listData = $listed->structured() ?: $listed->json();
			self::assertContains($formId, array_column($listData['forms'] ?? [], 'id'));
			$read = $basic->tool('forms_read', ['id' => $formId]);
			self::assertTrue($read->ok(), $read->describe());
			self::assertArrayNotHasKey('hash', $read->structured() ?: $read->json());

			$submitOnly = $basic->tool('forms_read_responses', ['id' => $formId]);
			self::assertTrue($submitOnly->isError(), 'submit permission and own-submission visibility do not grant MCP all-results access');
			self::assertStringNotContainsString('private OCS response canary', $submitOnly->text());

			$this->request($alice, 'PATCH', '/' . $formId . '/shares/' . $shareId, [
				'keyValuePairs' => ['permissions' => ['submit', 'results']],
			]);
			$results = $basic->tool('forms_read_responses', ['id' => $formId]);
			self::assertTrue($results->ok(), $results->describe());
			$resultsData = $results->structured() ?: $results->json();
			self::assertSame('private OCS response canary', $resultsData['submissions'][0]['answers'][0]['text'] ?? null);

			$this->request($alice, 'PATCH', '/' . $formId . '/shares/' . $shareId, [
				'keyValuePairs' => ['permissions' => ['submit']],
			]);
			$revoked = $basic->tool('forms_read_responses', ['id' => $formId]);
			self::assertTrue($revoked->isError(), 'a later native permission revocation blocks the next OCS read');
			self::assertStringNotContainsString('private OCS response canary', $revoked->text());

			$bearer = new McpClient(LoginFactory::bearer(Fixtures::BOB));
			$native = $bearer->tool('forms_list');
			self::assertTrue($native->ok(), $native->describe(), 'MCP bearer uses the in-process official Forms service path');
		} finally {
			if ($formId > 0 && $alice !== null) {
				$this->request($alice, 'DELETE', '/' . $formId, null);
			}
			ToolPermissionFixture::restore($toolSnapshot);
			foreach ($previousUsers as $uid => $state) {
				$policy->setEligible($uid, $state['eligible']);
				$policy->setConnected($uid, $state['connected']);
			}
			$policy->setGlobalEnabled($previousGlobal);
			if ($previousOfficial === '__mcp_it_missing__') {
				$config->deleteSystemValue($setting);
			} else {
				$config->setSystemValue($setting, $previousOfficial);
			}
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
		self::assertContains((int)($decoded['ocs']['meta']['statuscode'] ?? 0), [100, 200, 201], substr($response['body'], 0, 600));
		$data = $decoded['ocs']['data'] ?? [];
		return is_array($data) ? $data : ['value' => $data];
	}
}
