<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;

/** End-to-end contracts for the two settings surfaces, including the middleware and the saved revision. */
final class ToolPermissionsHttpTest extends IntegrationTestCase {
    private function request(string $user, string $method, string $path, ?array $body = null): array {
        return Http::request($method, Http::BASE . '/index.php/apps/mcp/settings/' . $path, [
            'Authorization: ' . LoginFactory::basic($user, false)->authorization(), 'OCS-APIRequest: true',
            'Content-Type: application/json', 'Accept: application/json',
        ], $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testConflictingGroupsThenUserOverrideAndResetThroughRealAdminRoutes(): void {
        $users = \OCP\Server::get(\OCP\IUserManager::class);
        $groups = \OCP\Server::get(\OCP\IGroupManager::class);
        $suffix = bin2hex(random_bytes(5));
        $user = $users->createUser('mcp-perm-' . $suffix, bin2hex(random_bytes(24)));
        self::assertNotFalse($user);
        $allow = $groups->createGroup('mcp-allow-' . $suffix);
        $block = $groups->createGroup('mcp-block-' . $suffix);
        try {
            $allow->addUser($user);
            $block->addUser($user);
            $this->assign('group', $allow->getGID(), ['files_edit' => 'allow', '_service' => 'allow']);
            $this->assign('group', $block->getGID(), ['files_edit' => 'deny', '_service' => 'deny']);
            $policy = \OCP\Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
            self::assertFalse($policy->eligible($user->getUID()));
            self::assertFalse($policy->toolGranted($user->getUID(), 'files_edit', 'files', 'edit'));
            $this->assign('user', $user->getUID(), ['files_edit' => 'allow', '_service' => 'allow']);
            self::assertTrue($policy->eligible($user->getUID()));
            self::assertTrue($policy->toolGranted($user->getUID(), 'files_edit', 'files', 'edit'));
            $this->assign('user', $user->getUID(), ['files_edit' => 'inherit', '_service' => 'inherit']);
            self::assertFalse($policy->eligible($user->getUID()));
            self::assertFalse($policy->toolGranted($user->getUID(), 'files_edit', 'files', 'edit'));
        } finally {
            $allow?->delete();
            $block?->delete();
            $user->delete();
        }
    }

    private function assign(string $kind, string $subject, array $changes): void {
        $path = 'tool-permissions';
        $detail = $this->request('admin', 'GET', $path . '?' . http_build_query(compact('kind', 'subject')));
        self::assertSame(200, $detail['status'], $detail['body']);
        $body = compact('kind', 'subject', 'changes') + ['revision' => json_decode($detail['body'], true)['revision']];
        $preview = $this->request('admin', 'POST', $path, $body + ['preview' => true]);
        self::assertSame(200, $preview['status'], $preview['body']);
        $result = json_decode($preview['body'], true);
        self::assertArrayHasKey('impact', $result);
        $commit = $this->request('admin', 'POST', $path, $body + ['preview' => false, 'previewId' => $result['previewId']]);
        self::assertSame(200, $commit['status'], $commit['body']);
    }

    public function testGroupWithOnlyPublicLinksShowsBothSharingToolsAsAllowed(): void {
        $groups = \OCP\Server::get(\OCP\IGroupManager::class);
        $group = $groups->createGroup('mcp-link-' . bin2hex(random_bytes(5)));
        try {
            $group->addUser(\OCP\Server::get(\OCP\IUserManager::class)->get(Fixtures::ALICE));
            $permissions = \OCP\Server::get(\OCA\Mcp\Access\Grants\Groups\GroupGrantsService::class);
            $revision = \OCP\Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['revision'];
            $grants = ['files' => ['link']];
            $preview = $permissions->preview($revision, 'admin', $group->getGID(), $grants);
            $permissions->save($revision, 'admin', $group->getGID(), $grants, $preview['previewId']);
            $detail = $this->request('admin', 'GET', 'tool-permissions?' . http_build_query(['kind' => 'group', 'subject' => $group->getGID()]));
            self::assertSame(200, $detail['status'], $detail['body']);
            $tools = array_column(json_decode($detail['body'], true)['tools'], null, 'name');
            foreach (['files_share', 'files_unshare'] as $name) {
                self::assertTrue($tools[$name]['effective'], $name);
                $origin = $this->request('admin', 'GET', 'tool-permissions/origin?' . http_build_query(['subject' => Fixtures::ALICE, 'tool' => $name]));
                self::assertSame(200, $origin['status'], $origin['body']);
                $sources = array_column(json_decode($origin['body'], true)['groups'], null, 'id');
                self::assertTrue($sources[$group->getGID()]['effective']);
            }
        } finally {
            $group?->delete();
        }
    }

    public function testNativeMembershipLossRevokesEvenWhenAccessReturnsBeforeTheNextRequest(): void {
        $suffix = bin2hex(random_bytes(5));
        $uid = 'mcp-member-' . $suffix;
        $user = \OCP\Server::get(\OCP\IUserManager::class)->createUser($uid, bin2hex(random_bytes(24)));
        $groups = \OCP\Server::get(\OCP\IGroupManager::class);
        $allow = $groups->createGroup('mcp-member-allow-' . $suffix);
        $deny = $groups->createGroup('mcp-member-deny-' . $suffix);
        $policy = \OCP\Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
        $global = $policy->globalEnabled();
        try {
            $policy->setGlobalEnabled(true);
            $this->assign('group', $allow->getGID(), ['_service' => 'allow']);
            $this->assign('group', $deny->getGID(), ['_service' => 'deny']);
            $allow->addUser($user);
            $policy->setConnected($uid, true);
            $original = LoginFactory::bearer($uid);
            $allow->removeUser($user);
            $allow->addUser($user);
            self::assertTrue($policy->eligible($uid));
            $this->assertRevoked($original);

            $replacement = LoginFactory::bearer($uid);
            $deny->addUser($user);
            $deny->removeUser($user);
            self::assertTrue($policy->eligible($uid));
            $this->assertRevoked($replacement);
        } finally {
            $allow?->delete();
            $deny?->delete();
            $user->delete();
            $policy->setGlobalEnabled($global);
        }
    }

    private function assertRevoked(\OCA\Mcp\Tests\Integration\Support\Credential $login): void {
        $response = Http::request('POST', Http::BASE . '/index.php/apps/mcp/', [
            'Authorization: ' . $login->authorization(), 'Content-Type: application/json', 'Accept: application/json, text/event-stream',
        ], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object)[]], JSON_THROW_ON_ERROR));
        self::assertSame(401, $response['status'], 'Restoring service must not revive an earlier credential');
    }

    public function testOnlyTheEditedOAuthConnectionLosesReadAccess(): void {
        $uid = 'mcp-connections-' . bin2hex(random_bytes(5));
        $user = \OCP\Server::get(\OCP\IUserManager::class)->createUser($uid, bin2hex(random_bytes(24)));
        $policy = \OCP\Server::get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
        $global = $policy->globalEnabled();
        try {
            $policy->setGlobalEnabled(true);
            $policy->setEligible($uid, true);
            $policy->setConnected($uid, true);
            $first = LoginFactory::bearer($uid);
            $second = LoginFactory::bearer($uid);
            $row = \OCP\Server::get(\OCA\Mcp\OAuth\OAuthStore::class)->findByAccess(
                \OCP\Server::get(\OCA\Mcp\OAuth\TokenHasher::class)->hash(substr($first->authorization(), 7)));
            $path = 'my/connections/' . $row['id'] . '/tools';
            $foreign = $this->request(Fixtures::BOB, 'GET', $path);
            self::assertSame(404, $foreign['status'], $foreign['body']);
            $before = $this->request($uid, 'GET', $path);
            self::assertSame(200, $before['status'], $before['body']);
            $reply = $this->request($uid, 'PUT', $path, [
                'revision' => json_decode($before['body'], true)['revision'], 'tools' => ['files_list'],
            ]);
            self::assertSame(200, $reply['status'], $reply['body']);
            foreach ([[$first, false], [$second, true]] as [$login, $canRead]) {
                $response = Http::request('POST', Http::BASE . '/index.php/apps/mcp/', [
                    'Authorization: ' . $login->authorization(), 'Content-Type: application/json', 'Accept: application/json, text/event-stream',
                ], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object)[]], JSON_THROW_ON_ERROR));
                self::assertSame(200, $response['status']);
                $available = array_column(json_decode($response['body'], true)['result']['tools'], 'name');
                self::assertSame($canRead, in_array('files_read', $available, true));
                self::assertContains('files_list', $available);
            }
            $this->assign('user', $uid, ['_service' => 'deny']);
            $this->assign('user', $uid, ['_service' => 'allow']);
            foreach ([$first, $second] as $login) {
                $this->assertRevoked($login);
            }
        } finally {
            $user->delete();
            $policy->setGlobalEnabled($global);
        }
    }

    public function testAdminEditorAndBasicProfileRespectDifferentAuthorities(): void {
        $target = 'tool-permissions?kind=user&subject=' . rawurlencode(Fixtures::ALICE);
        $this->assertSame(403, $this->request(Fixtures::ALICE, 'GET', $target)['status']);
        $detail = $this->request('admin', 'GET', $target);
        $this->assertSame(200, $detail['status'], $detail['body']);
        $decoded = json_decode($detail['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('state', $decoded['service']);
        self::assertContains('files_read', array_column($decoded['tools'], 'name'));

        $path = 'my/connections/0/tools';
        $personal = $this->request(Fixtures::ALICE, 'GET', $path);
        self::assertSame(200, $personal['status'], $personal['body']);
        $selection = json_decode($personal['body'], true, flags: JSON_THROW_ON_ERROR);
        $bad = $this->request(Fixtures::ALICE, 'PUT', $path, ['revision' => $selection['revision'], 'tools' => ['unregistered_tool']]);
        self::assertSame(400, $bad['status']);
        $accepted = $this->request(Fixtures::ALICE, 'PUT', $path, ['revision' => $selection['revision'], 'tools' => []]);
        self::assertSame(200, $accepted['status'], $accepted['body']);
        $outdated = $this->request(Fixtures::ALICE, 'PUT', $path, ['revision' => $selection['revision'], 'tools' => []]);
        self::assertSame(409, $outdated['status']);
        $selected = array_filter($selection['tools'], static fn ($row) => $row['selected']);
        $variants = array_filter(array_column($selected, 'variants', 'name'));
        $restore = $this->request(Fixtures::ALICE, 'PUT', $path, [
            'revision' => json_decode($accepted['body'], true, flags: JSON_THROW_ON_ERROR)['revision'],
            'tools' => array_column($selected, 'name'), 'variants' => $variants,
        ]);
        self::assertSame(200, $restore['status'], $restore['body']);
    }
}
