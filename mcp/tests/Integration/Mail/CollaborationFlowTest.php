<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Mail;

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

/** One user can safely carry a Forms answer into a confirmed Talk summary through a single MCP connection. */
#[Group('forms')]
#[Group('talk')]
final class CollaborationFlowTest extends IntegrationTestCase {
    private const FORMS_API = Http::BASE . '/ocs/v2.php/apps/forms/api/v3/forms';
    private const TALK_API = Http::BASE . '/ocs/v2.php/apps/spreed/api/v4/room/';
    private array $toolPermissionSnapshot = [];
    private array $policySnapshot = [];

    protected function setUp(): void {
        parent::setUp();
        if (getenv('MCP_IT_WITH_FORMS') !== '1') {
            $this->markTestSkipped('Pinned Forms app is provisioned only in the opt-in Mail cell');
        }
        $config = Server::get(IConfig::class);
        $this->policySnapshot['service'] = $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '__mcp_it_missing__');
        foreach ([Fixtures::ALICE, Fixtures::BOB] as $uid) {
            foreach ([GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY] as $key) {
                $this->policySnapshot['users'][$uid][$key] = $config->getUserValue($uid, 'mcp', $key, '__mcp_it_missing__');
            }
        }
        $this->toolPermissionSnapshot = ToolPermissionFixture::capture([Fixtures::ALICE], [
            'forms_read_responses', 'talk_create_group', 'talk_read_messages', 'talk_list_conversations', 'talk_publish_summary',
        ]);
    }

    protected function tearDown(): void {
        try {
            if ($this->toolPermissionSnapshot !== []) {
                ToolPermissionFixture::restore($this->toolPermissionSnapshot);
            }
            $config = Server::get(IConfig::class);
            if ($this->policySnapshot['service'] === '__mcp_it_missing__') {
                $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY);
            } else {
                $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->policySnapshot['service']);
            }
            foreach ($this->policySnapshot['users'] ?? [] as $uid => $values) {
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

    public function testFormsResponseCanBePreviewedAndConfirmedAsATalkSummary(): void {
        $alice = Fixtures::ALICE;
        $bob = Fixtures::BOB;
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        foreach ([$alice, $bob] as $uid) {
            $policy->setEligible($uid, true);
            $policy->setConnected($uid, true);
        }
        ToolPermissionFixture::assign($alice, [
            'forms_read_responses' => 'allow',
            'talk_create_group' => 'allow',
            'talk_read_messages' => 'allow',
            'talk_list_conversations' => 'allow',
            'talk_publish_summary' => 'allow',
        ]);
        $aliceLogin = LoginFactory::basic($alice);
        $bobLogin = LoginFactory::basic($bob, false);
        $form = $this->request($aliceLogin, 'POST', '', null);
        $formId = (int)($form['id'] ?? 0);
        self::assertGreaterThan(0, $formId, 'Forms API returned no form id');
        $token = null;
        try {
            $question = $this->request($aliceLogin, 'POST', '/' . $formId . '/questions', ['type' => 'short', 'text' => 'Decision']);
            $answer = 'Approved for the next release';
            $this->request($aliceLogin, 'POST', '/' . $formId . '/shares', [
                'shareType' => 0, 'shareWith' => $bob, 'permissions' => ['submit'],
            ]);
            $this->request($bobLogin, 'POST', '/' . $formId . '/submissions', [
                'answers' => [(string)$question['id'] => [$answer]],
            ]);

            $client = new McpClient($aliceLogin);
            $responses = $client->tool('forms_read_responses', ['id' => $formId]);
            self::assertTrue($responses->ok(), $responses->describe());
            $data = $responses->structured() ?: $responses->json();
            self::assertSame($answer, $data['submissions'][0]['answers'][0]['text'] ?? null,
                'the Talk summary uses the answer returned by Forms');

            $room = $client->write('talk_create_group', ['name' => 'Forms to Talk ' . bin2hex(random_bytes(4)), 'participants' => [$bob]]);
            self::assertTrue($room->ok(), $room->describe());
            $roomData = $room->structured() ?: $room->json();
            $token = (string)($roomData['conversation_token'] ?? '');
            self::assertNotSame('', $token, 'Talk returned a conversation token');
            $before = $this->messages($client, $token);

            $summary = 'Forms response: ' . $data['submissions'][0]['answers'][0]['text'];
            $preview = $client->tool('talk_publish_summary', ['conversation_token' => $token, 'message' => $summary]);
            self::assertTrue($preview->ok(), $preview->describe());
            $plan = $preview->structured() ?: $preview->json();
            self::assertSame($summary, $plan['draft']['message'] ?? null);
            self::assertNotSame('', $plan['plan_state'] ?? '');
            self::assertSame($before, $this->messages($client, $token), 'the preview does not post a Talk message');

            $confirmed = $client->tool('talk_publish_summary', [
                'conversation_token' => $token, 'message' => $summary, 'confirm' => true, 'plan_state' => $plan['plan_state'],
            ]);
            self::assertTrue($confirmed->ok(), $confirmed->describe());
            $after = $this->messages($client, $token);
            self::assertSame([...array_column($before, 'text'), $summary], array_column($after, 'text'),
                'confirmation posts exactly the Forms-derived summary once');
        } finally {
            try {
                if (is_string($token) && $token !== '') {
                    $headers = ['Authorization: ' . $aliceLogin->authorization(), 'OCS-APIRequest: true', 'Accept: application/json'];
                    $deleted = Http::request('DELETE', self::TALK_API . rawurlencode($token), $headers);
                    self::assertSame(200, $deleted['status'], 'Talk API deletes the flow room');
                }
            } finally {
                $this->request($aliceLogin, 'DELETE', '/' . $formId, null);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function messages(McpClient $client, string $token): array {
        $reply = $client->tool('talk_read_messages', ['conversation_token' => $token, 'limit' => 50]);
        self::assertTrue($reply->ok(), $reply->describe());
        $data = $reply->structured() ?: $reply->json();
        return $data['messages'] ?? [];
    }

    /** @return array<string, mixed> */
    private function request(Credential $credential, string $method, string $path, ?array $body): array {
        $headers = [
            'Authorization: ' . $credential->authorization(),
            'OCS-APIRequest: true',
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        $response = Http::request($method, self::FORMS_API . $path . '?format=json', $headers,
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
        self::assertContains($response['status'], [200, 201], substr($response['body'], 0, 600));
        $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertContains((int)($decoded['ocs']['meta']['statuscode'] ?? 0), [200, 201], substr($response['body'], 0, 600));
        $data = $decoded['ocs']['data'] ?? [];
        return is_array($data) ? $data : ['value' => $data];
    }
}
