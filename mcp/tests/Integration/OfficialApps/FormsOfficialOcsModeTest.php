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
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

#[Group('official-apps')]
final class FormsOfficialOcsModeTest extends IntegrationTestCase {
	private const FORMS_API = Http::BASE . '/ocs/v2.php/apps/forms/api/v3/forms';
	private const EXTENDED_READS = ['forms_read_settings', 'forms_read_question', 'forms_list_shares', 'forms_read_own_response', 'forms_read_response'];

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
			$previousUsers[$uid] = ['eligible' => $policy->eligible($uid), 'connected' => $policy->connected($uid), 'resultsGrant' => $policy->granted($uid, 'forms', 'read_responses')];
		}
		$extraReads = $this->hasExtendedOfficialForms() ? self::EXTENDED_READS : [];
		$toolSnapshot = ToolPermissionFixture::capture([Fixtures::ALICE, Fixtures::BOB], [
			'forms_list', 'forms_read', 'forms_read_responses', 'forms_capabilities', ...$extraReads,
		]);
		$formId = 0;
		$alice = null;

		try {
			$config->setSystemValue($setting, true);
			$policy->setGlobalEnabled(true);
			foreach ([Fixtures::ALICE, Fixtures::BOB] as $uid) {
				$policy->setEligible($uid, true);
				$policy->setConnected($uid, true);
				$policy->setGrant($uid, 'forms', 'read_responses', true);
				ToolPermissionFixture::assign($uid, [
					'forms_list' => 'allow', 'forms_read' => 'allow', 'forms_read_responses' => 'allow',
					'forms_capabilities' => 'allow', ...array_fill_keys($extraReads, 'allow'),
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

			$bobBearer = LoginFactory::bearer(Fixtures::BOB);
			$bearer = new McpClient($bobBearer);
			foreach ([$bob, $bobBearer] as $credential) {
				$names = $this->toolNames($credential);
				self::assertSame($extraReads, array_values(array_intersect(self::EXTENDED_READS, $names)));
				foreach (['forms_create', 'forms_update', 'forms_read_links', 'forms_list_response_files'] as $tool) {
					self::assertNotContains($tool, $names);
				}
			}
			$capabilities = $this->read($bearer, 'forms_capabilities', []);
			self::assertSame($extraReads, $capabilities['officialReadTools']);
			self::assertFalse($capabilities['guardedWrites']);
			$native = $bearer->tool('forms_list');
			self::assertTrue($native->ok(), $native->describe(), 'MCP bearer uses the in-process official Forms service path');
			if ($extraReads === []) {
				self::assertSame([], $capabilities['officialWriteTools']);
				return;
			}

			$choice = $this->request($alice, 'POST', '/' . $formId . '/questions', ['type' => 'multiple', 'text' => 'OCS options']);
			$this->request($alice, 'POST', '/' . $formId . '/questions/' . $choice['id'] . '/options', [
				'optionTexts' => ['synthetic alpha', 'synthetic beta'],
			]);

			$owner = new McpClient(LoginFactory::bearer(Fixtures::ALICE));
			$formArgs = ['id' => $formId];
			$questionArgs = $formArgs + ['question_id' => $questionId];
			$responseArgs = $formArgs + ['submission_id' => $submissionId];
			foreach ([$basic, $bearer] as $client) {
				$readQuestion = $this->read($client, 'forms_read_question', $questionArgs);
				self::assertSame('OCS reader question', $readQuestion['text']);
				$choiceArgs = $formArgs + ['question_id' => $choice['id'], 'limit' => 1];
				$first = $this->read($client, 'forms_read_question', $choiceArgs);
				self::assertTrue($first['hasMore']);
				self::assertSame('synthetic alpha', $first['options'][0]['text']);
				$second = $this->read($client, 'forms_read_question', $choiceArgs + ['offset' => $first['nextOffset']]);
				self::assertFalse($second['hasMore']);
				self::assertSame('synthetic beta', $second['options'][0]['text']);
				$ownResponse = $this->read($client, 'forms_read_own_response', $responseArgs);
				self::assertSame('private OCS response canary', $ownResponse['answers'][0]['text']);
				$sharedSettings = $this->read($client, 'forms_read_settings', $formArgs);
				self::assertSame($formId, $sharedSettings['id']);
				self::assertArrayNotHasKey('shares', $sharedSettings);
				self::assertTrue($client->tool('forms_list_shares', $formArgs)->isError(), 'share inventory is owner-only');
				self::assertTrue($client->tool('forms_read_response', $responseArgs)->isError());
			}
			$settings = $this->read($owner, 'forms_read_settings', $formArgs);
			self::assertSame($formId, $settings['id']);
			self::assertArrayNotHasKey('hash', $settings);
			self::assertArrayNotHasKey('shares', $settings);
			$publicShare = $this->request($alice, 'POST', '/' . $formId . '/shares', [
				'shareType' => 3, 'permissions' => ['submit'],
			]);
			self::assertNotEmpty($publicShare['shareWith']);
			$shares = $this->read($owner, 'forms_list_shares', $formArgs);
			self::assertContains($shareId, array_column($shares['shares'], 'id'));
			self::assertStringNotContainsString($publicShare['shareWith'], json_encode($shares, JSON_THROW_ON_ERROR));
			self::assertTrue($owner->tool('forms_read_own_response', $responseArgs)->isError(), 'own-response read never grants another user’s response');
			$ownerResponse = $this->read($owner, 'forms_read_response', $responseArgs);
			self::assertSame('private OCS response canary', $ownerResponse['answers'][0]['text']);
			self::assertArrayNotHasKey('userId', $ownerResponse);
			self::assertTrue($owner->tool('forms_read_response', $responseArgs + ['include_respondents' => true])->isError());

			$this->request($alice, 'PATCH', '/' . $formId . '/shares/' . $shareId, [
				'keyValuePairs' => ['permissions' => ['submit', 'results']],
			]);
			foreach ([$basic, $bearer] as $client) {
				$delegated = $this->read($client, 'forms_read_response', $responseArgs);
				self::assertSame('private OCS response canary', $delegated['answers'][0]['text']);
			}
			$this->request($alice, 'PATCH', '/' . $formId . '/shares/' . $shareId, [
				'keyValuePairs' => ['permissions' => ['submit']],
			]);
			foreach ([$basic, $bearer] as $client) {
				self::assertTrue($client->tool('forms_read_response', $responseArgs)->isError());
			}

			ToolPermissionFixture::assign(Fixtures::BOB, ['forms_read_question' => 'deny']);
			foreach ([[$basic, $bob], [$bearer, $bobBearer]] as [$client, $credential]) {
				self::assertNotContains('forms_read_question', $this->toolNames($credential));
				self::assertFalse($client->tool('forms_read_question', $questionArgs)->ok());
			}
			ToolPermissionFixture::assign(Fixtures::BOB, ['forms_read_question' => 'allow']);
			$this->request($alice, 'DELETE', '/' . $formId . '/shares/' . $shareId, null);
			foreach ([$basic, $bearer] as $client) {
				$revokedQuestion = $client->tool('forms_read_question', $questionArgs);
				self::assertTrue($revokedQuestion->isError());
				self::assertStringNotContainsString('OCS reader question', $revokedQuestion->text());
				$revokedResponse = $client->tool('forms_read_own_response', $responseArgs);
				self::assertTrue($revokedResponse->isError());
				self::assertStringNotContainsString('private OCS response canary', $revokedResponse->text());
			}

			// The native group ACL must be authoritative even without the old Forms group observer.
			$group = Server::get(IGroupManager::class)->get(Fixtures::EMPTY);
			$member = Server::get(IUserManager::class)->get(Fixtures::BOB);
			self::assertNotNull($group);
			self::assertNotNull($member);
			$this->request($alice, 'POST', '/' . $formId . '/shares', [
				'shareType' => 1, 'shareWith' => Fixtures::EMPTY, 'permissions' => ['submit'],
			]);
			try {
				$group->addUser($member);
				$groupBearer = new McpClient(LoginFactory::bearer(Fixtures::BOB));
				self::assertSame('OCS reader question', $this->read($groupBearer, 'forms_read_question', $questionArgs)['text']);
			} finally {
				$group->removeUser($member);
			}
			self::assertTrue($groupBearer->tool('forms_read_question', $questionArgs)->isError());

		} finally {
			if ($formId > 0 && $alice !== null) {
				$this->request($alice, 'DELETE', '/' . $formId, null);
			}
			ToolPermissionFixture::restore($toolSnapshot);
			foreach ($previousUsers as $uid => $state) {
				$policy->setEligible($uid, $state['eligible']);
				$policy->setConnected($uid, $state['connected']);
				$policy->setGrant($uid, 'forms', 'read_responses', $state['resultsGrant']);
			}
			$policy->setGlobalEnabled($previousGlobal);
			if ($previousOfficial === '__mcp_it_missing__') {
				$config->deleteSystemValue($setting);
			} else {
				$config->setSystemValue($setting, $previousOfficial);
			}
		}
	}

	public function testOfficialAuthoringUsesConfirmedToolsAndIndependentNativeAccess(): void {
		if (getenv('MCP_IT_WITH_FORMS') !== '1' || getenv('MCP_IT_MAIL_GUARD') !== '0' || !$this->hasExtendedOfficialForms()) {
			$this->markTestSkipped('Requires the disposable stock Forms 5.4.4 or 5.4.5 profile.');
		}
		$config = Server::get(IConfig::class);
		$setting = 'app.mcp.official-apps.forms';
		$previousOfficial = $config->getSystemValue($setting, '__mcp_it_missing__');
		$policy = Server::get(GrantPolicy::class);
		$previousGlobal = $policy->globalEnabled();
		$actors = [Fixtures::ALICE, Fixtures::BOB];
		$tools = ['forms_create', 'forms_clone', 'forms_update', 'forms_set_state', 'forms_delete_form', 'forms_create_question', 'forms_clone_question',
			'forms_update_question', 'forms_delete_question', 'forms_reorder_questions', 'forms_add_options', 'forms_update_option',
			'forms_delete_option', 'forms_reorder_options', 'forms_share', 'forms_unshare', 'forms_read_responses', 'forms_summarize_responses'];
		$previousTools = ToolPermissionFixture::capture($actors, $tools);
		$previous = [];
		$formId = null;
		$alice = null;
		try {
			$config->setSystemValue($setting, true);
			$policy->setGlobalEnabled(true);
			foreach ($actors as $uid) {
				$previous[$uid] = [$policy->eligible($uid), $policy->connected($uid),
					$policy->granted($uid, 'forms', 'edit'), $policy->granted($uid, 'forms', 'public_link')];
				$policy->setEligible($uid, true);
				$policy->setConnected($uid, true);
				$policy->setGrant($uid, 'forms', 'edit', true);
				$policy->setGrant($uid, 'forms', 'public_link', true);
				ToolPermissionFixture::assign($uid, array_fill_keys($tools, 'allow'));
			}
			$alice = LoginFactory::basic(Fixtures::ALICE);
			$bearer = LoginFactory::bearer(Fixtures::ALICE);
			$basicClient = new McpClient($alice);
			$owner = new McpClient($bearer);
			$foreign = new McpClient(LoginFactory::bearer(Fixtures::BOB));
			self::assertContains('forms_create', $this->toolNames($bearer));
			$args = ['title' => 'Synthetic customer survey', 'description' => 'Official Forms test data'];
			$plan = $this->read($owner, 'forms_create', $args);
			$before = $this->request($alice, 'GET', '', null);
			self::assertNotContains($args['title'], array_column($before, 'title'));
			$approved = $this->approvedAuthoring($args, $plan);
			$created = $this->read($owner, 'forms_create', $approved);
			$formId = $created['operation']['references']['form_id'] ?? $created['data']['id'] ?? null;
			self::assertSame('succeeded', $created['operation']['state'] ?? null, json_encode($created, JSON_THROW_ON_ERROR));
			self::assertIsInt($formId);
			$target = ['id' => $formId];
			$replay = $this->read($owner, 'forms_create', $approved);
			self::assertTrue($replay['operation']['replayed']);
			$after = $this->request($alice, 'GET', '', null);
			self::assertSame(1, count(array_keys(array_column($after, 'title'), $args['title'], true)));
			self::assertFalse($foreign->tool('forms_update', $target + ['fields' => ['title' => 'Forbidden']])->ok());

			$edited = $target + ['fields' => ['title' => 'Customer survey edited']];
			$this->writeAuthoring($owner, 'forms_update', $edited);
			$oldPlan = $this->read($owner, 'forms_update', $edited);
			$this->request($alice, 'PATCH', '/' . $formId, ['keyValuePairs' => ['title' => 'Changed from native Forms']]);
			$stale = $owner->tool('forms_update', $this->approvedAuthoring($edited, $oldPlan));
			self::assertSame('Changed from native Forms', $this->request($alice, 'GET', '/' . $formId, null)['title']);
			self::assertNotSame('succeeded', ($stale->structured() ?: $stale->json())['operation']['state'] ?? null);
			$revokedPlan = $this->read($owner, 'forms_update', $edited);
			ToolPermissionFixture::assign(Fixtures::ALICE, ['forms_update' => 'deny']);
			self::assertFalse($owner->tool('forms_update', $this->approvedAuthoring($edited, $revokedPlan))->ok());
			self::assertSame('Changed from native Forms', $this->request($alice, 'GET', '/' . $formId, null)['title']);

			$question = $this->writeAuthoring($basicClient, 'forms_create_question', $target + ['type' => 'dropdown', 'text' => 'Choose a synthetic option']);
			$q = $target + ['question_id' => $question['questionId']];
			$this->writeAuthoring($owner, 'forms_update_question', $q + ['fields' => ['isRequired' => true, 'text' => 'Choose one']]);
			$cloned = $this->writeAuthoring($owner, 'forms_clone_question', $q);
			$cloneId = $cloned['questionId'];
			$this->writeAuthoring($owner, 'forms_reorder_questions', $target + ['order' => [$cloneId, $q['question_id']]]);
			$this->writeAuthoring($owner, 'forms_delete_question', $target + ['question_id' => $cloneId]);
			$added = $this->writeAuthoring($basicClient, 'forms_add_options', $q + ['texts' => ['First', 'Second', 'Disposable']]);
			$ids = array_column($added['options'], 'id');
			$this->writeAuthoring($owner, 'forms_update_option', $q + ['option_id' => $ids[0], 'text' => 'Renamed']);
			$this->writeAuthoring($owner, 'forms_delete_option', $q + ['option_id' => $ids[2]]);
			$this->writeAuthoring($owner, 'forms_reorder_options', $q + ['order' => [$ids[1], $ids[0]]]);
			$copy = $this->writeAuthoring($owner, 'forms_clone', $target + ['title' => 'Synthetic private copy']);
			$this->writeAuthoring($owner, 'forms_delete_form', ['id' => $copy['id']]);

			$link = $this->writeAuthoring($owner, 'forms_share', $target + ['kind' => 'link', 'permissions' => ['submit']]);
			self::assertSame(['submit'], $link['permissions']);
			$url = parse_url($link['url']);
			$hash = basename($url['path']);
			self::assertSame(200, Http::request('GET', Http::BASE . $url['path'])['status']);
			$answer = ['answers' => [(string)$q['question_id'] => [(string)$ids[0]]], 'shareHash' => $hash];
			$submission = Http::request('POST', self::FORMS_API . '/' . $formId . '/submissions?format=json',
				['OCS-APIRequest: true', 'Accept: application/json', 'Content-Type: application/json'], json_encode($answer, JSON_THROW_ON_ERROR));
			self::assertSame(201, $submission['status']);
			self::assertFalse($foreign->tool('forms_read_responses', $target)->ok());
			$summary = $this->read($owner, 'forms_summarize_responses', $target);
			self::assertSame(1, $summary['submissionsAnalyzed']);
			self::assertFalse($owner->tool('forms_delete_question', $q)->ok(), 'an answered question is preserved');
			$this->writeAuthoring($owner, 'forms_unshare', $target + ['share_id' => $link['shareId']]);
			$retry = Http::request('POST', self::FORMS_API . '/' . $formId . '/submissions?format=json',
				['OCS-APIRequest: true', 'Content-Type: application/json'], json_encode($answer, JSON_THROW_ON_ERROR));
			self::assertContains($retry['status'], [403, 404]);
			$this->writeAuthoring($owner, 'forms_set_state', $target + ['state' => 1]);
			$this->writeAuthoring($owner, 'forms_delete_form', $target);
			$formId = null;
			self::assertNotContains($target['id'], array_column($this->request($alice, 'GET', '', null), 'id'));
		} finally {
			if ($formId !== null && $alice !== null) {
				$this->request($alice, 'DELETE', '/' . $formId, null);
			}
			ToolPermissionFixture::restore($previousTools);
			foreach ($previous as $uid => [$eligible, $connected, $edit, $link]) {
				$policy->setEligible($uid, $eligible);
				$policy->setConnected($uid, $connected);
				$policy->setGrant($uid, 'forms', 'edit', $edit);
				$policy->setGrant($uid, 'forms', 'public_link', $link);
			}
			$policy->setGlobalEnabled($previousGlobal);
			if ($previousOfficial === '__mcp_it_missing__') {
				$config->deleteSystemValue($setting);
			} else {
				$config->setSystemValue($setting, $previousOfficial);
			}
		}
	}

	private function hasExtendedOfficialForms(): bool {
		return in_array(Server::get(IAppManager::class)->getAppVersion('forms'), ['5.4.4', '5.4.5'], true);
	}

	private function approvedAuthoring(array $arguments, array $plan): array {
		return $arguments + [
			'confirm' => true, 'confirm_shared' => true, 'operation_id' => $plan['operation_id'], 'plan_state' => $plan['plan_state'],
		];
	}

	private function writeAuthoring(McpClient $client, string $tool, array $args): array {
		$plan = $this->read($client, $tool, $args);
		$result = $this->read($client, $tool, $this->approvedAuthoring($args, $plan));
		self::assertSame('succeeded', $result['operation']['state'] ?? null, $tool . ' ' . json_encode($result['operation'] ?? [], JSON_THROW_ON_ERROR));
		return $result['data'];
	}

	/** @return list<string> */
	private function toolNames(Credential $credential): array {
		$response = Http::request('POST', Http::BASE . '/index.php/apps/mcp/', [
			'Authorization: ' . $credential->authorization(),
			'Content-Type: application/json',
			'Accept: application/json, text/event-stream',
		], '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
		self::assertSame(200, $response['status']);
		$decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
		return array_column($decoded['result']['tools'], 'name');
	}

	private function read(McpClient $client, string $tool, array $arguments): array {
		$reply = $client->tool($tool, $arguments);
		self::assertTrue($reply->ok(), $reply->describe());
		return $reply->structured() ?: $reply->json();
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
