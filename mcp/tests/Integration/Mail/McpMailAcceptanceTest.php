<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory, McpClient, ToolPermissionFixture, ToolReply};
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/** Cross-delivery HTTP acceptance: held reply/Files identity survives reset, roles and archive. */
#[Group('mail')]
final class McpMailAcceptanceTest extends IntegrationTestCase {
    private const TOOLS = [
        'mail_write_draft', 'mail_delete_draft', 'mail_read_message', 'mail_attach_files', 'files_read',
        'mail_read_signature', 'mail_write_signature', 'mail_configure_mailboxes',
        'mail_set_message_flag', 'mail_create_tag', 'mail_set_message_tag',
        'mail_archive_message', 'mail_refresh_mailbox', 'mail_get_operation', 'mail_list_operations',
    ];
    private array $toolSnapshot = [];
    private array $settings = [];
    private string $serviceBefore = '__missing__';
    private McpClient $alice;
    private McpClient $bob;

    protected function setUp(): void {
        parent::setUp();
        if (getenv('MCP_IT_WITH_MAIL') !== '1' || getenv('MCP_IT_MAIL_GUARD') !== '1') { self::markTestSkipped('Mail cell only'); }
        $config = Server::get(IConfig::class);
        $this->serviceBefore = $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '__missing__');
        $this->toolSnapshot = ToolPermissionFixture::capture([MailActor::ALICE, MailActor::BOB], self::TOOLS);
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        foreach ([MailActor::ALICE, MailActor::BOB] as $uid) {
            foreach ([GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY] as $key) {
                $this->settings[$uid][$key] = $config->getUserValue($uid, 'mcp', $key, '__missing__');
            }
            $policy->setEligible($uid, true);
            $policy->setConnected($uid, true);
            ToolPermissionFixture::assign($uid, array_fill_keys(self::TOOLS, 'allow'));
        }
        $this->alice = new McpClient(LoginFactory::basic(MailActor::ALICE));
        $this->bob = new McpClient(LoginFactory::basic(MailActor::BOB));
    }

    protected function tearDown(): void {
        try {
            if ($this->toolSnapshot !== []) {
                ToolPermissionFixture::restore($this->toolSnapshot);
                $config = Server::get(IConfig::class);
                foreach ($this->settings as $uid => $values) {
                    foreach ($values as $key => $value) {
                        if ($value === '__missing__') { $config->deleteUserValue($uid, 'mcp', $key); }
                        else { $config->setUserValue($uid, 'mcp', $key, $value); }
                    }
                }
                if ($this->serviceBefore === '__missing__') { $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY); }
                else { $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->serviceBefore); }
            }
        } finally { parent::tearDown(); }
    }



    public function testHeldReplyAndFilesSurviveResetRoleChangeAndArchive(): void {
        $owner = MailActor::ALICE;
        $account = MailActor::run($owner, 'accounts')['accounts'][0]['id'];
        $fixture = MailActor::run($owner, 'c3-fixture', ['account_id' => $account]);
        $root = Server::get(IRootFolder::class)->getUserFolder($owner);
        $path = '/c7-reply-' . bin2hex(random_bytes(6)) . '.txt';
        $bytes = "C7 reviewed Files bytes\n";
        $draftId = $tagId = null;
        $signature = null;
        $signatureLoaded = false;
        $replays = [];
        $manager = Server::get('OCA\\Mail\\Service\\MailManager');
        $drafts = Server::get('OCA\\Mail\\Service\\DraftsService');
        $messages = Server::get('OCA\\Mail\\Db\\MessageMapper');
        $tags = Server::get('OCA\\Mail\\Db\\TagMapper');
        try {
            $root->newFile(ltrim($path, '/'), $bytes);
            $sourceId = $fixture['boxes']['source'];
            $destinationId = $fixture['boxes']['destination'];
            $parentId = $fixture['messages'][0];
            $uid = $manager->getMessage($owner, $parentId)->getUid();
            $reply = $this->write('mail_write_draft', [
                'account_id' => $account, 'reply_to_message_id' => $parentId,
                'reply_mode' => 'reply_all', 'subject' => 'C7 held reply',
                'body_plain' => 'Explicit body before signature change.', 'add_files' => [$path],
            ], $replays);
            $draftId = $reply['draft']['id'];
            self::assertTrue($reply['draft']['sync_held']);
            self::assertCount(1, $reply['draft']['attachments']);
            $heldHash = $this->heldHash($drafts, $draftId, $owner);
            $safe = MailActor::run($owner, 'c1-safety');

            // D and C2 edits must not rewrite an already reviewed A reply.
            $signature = $this->data($this->alice->tool('mail_read_signature', ['account_id' => $account]))['signature'];
            $signatureLoaded = true;
            $this->write('mail_write_signature', ['account_id' => $account, 'signature' => 'C7 later signature'], $replays);
            $this->write('mail_configure_mailboxes', [
                'account_id' => $account, 'roles' => ['archive_mailbox_id' => $destinationId],
            ], $replays);
            $this->write('mail_set_message_flag', [
                'account_id' => $account, 'message_id' => $parentId, 'flag' => 'flagged', 'value' => true,
            ], $replays);
            $created = $this->write('mail_create_tag', [
                'name' => 'C7 exact ' . bin2hex(random_bytes(6)), 'color' => '#AABBCC',
            ], $replays);
            $tagId = $created['tag']['tag_id'];
            $this->write('mail_set_message_tag', [
                'account_id' => $account, 'message_id' => $parentId, 'tag_id' => $tagId, 'value' => true,
            ], $replays);

            // Emulate only this owned fixture's legacy cache. Default refresh cannot reset it.
            $q = Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
            $q->update('mail_messages')->set('tag_cache_profile', $q->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
                ->where($q->expr()->eq('id', $q->createNamedParameter($parentId)))->executeStatement();
            $refresh = ['account_id' => $account, 'mailbox_id' => $sourceId];
            self::assertFalse($this->alice->tool('mail_refresh_mailbox', $refresh)->ok());
            $reset = $this->write('mail_refresh_mailbox', $refresh + ['allow_cache_reset' => true], $replays);
            self::assertTrue($reset['operation']['mutation']['cache']['reset']);
            $source = $manager->getMailbox($owner, $sourceId);
            $newParent = $messages->findByUids($source, [$uid]);
            self::assertCount(1, $newParent);
            $newParentId = $newParent[0]->getId();
            self::assertNotSame($parentId, $newParentId);
            self::assertTrue($newParent[0]->getFlagFlagged());
            self::assertTrue($tags->exactAssociations()->has($owner, $newParentId, $tagId));
            $sourceSnapshot = Server::get('OCA\\Mail\\Sync\\CacheSnapshot')->capture($owner, $account, $sourceId);
            self::assertSame([], array_intersect($fixture['messages'], array_column($sourceSnapshot['messages'], 'id')));
            self::assertSame($heldHash, $this->heldHash($drafts, $draftId, $owner), 'Cache reset changed the held reply');

            $archive = $this->write('mail_archive_message', [
                'account_id' => $account, 'message_id' => $newParentId,
            ], $replays);
            $cache = $archive['operation']['mutation']['cache'];
            self::assertSame($destinationId, $cache['destination_mailbox_id']);
            self::assertSame('native_sync_pending', $cache['destination_state']);
            $this->write('mail_refresh_mailbox', ['account_id' => $account, 'mailbox_id' => $destinationId], $replays);
            $moved = $messages->findByUids($manager->getMailbox($owner, $destinationId), [$cache['destination_uid']]);
            self::assertCount(1, $moved);
            self::assertTrue($moved[0]->getFlagFlagged());
            self::assertTrue($tags->exactAssociations()->has($owner, $moved[0]->getId(), $tagId));
            // All fixture messages share Message-ID: the unrelated destination row stays untagged.
            $destination = Server::get('OCA\\Mail\\Sync\\CacheSnapshot')->capture($owner, $account, $destinationId);
            self::assertCount(2, $destination['messages']);
            foreach ($destination['messages'] as $row) {
                if ($row['id'] !== $moved[0]->getId()) {
                    self::assertFalse($tags->exactAssociations()->has($owner, $row['id'], $tagId));
                }
            }
            $snapshots = Server::get('OCA\\Mail\\Sync\\CacheSnapshot');
            $beforeReplay = [$snapshots->capture($owner, $account, $sourceId)['fingerprint'], $destination['fingerprint']];
            foreach ($replays as [$tool, $confirmed]) {
                $replay = $this->data($this->alice->tool($tool, $confirmed));
                self::assertTrue($replay['operation']['replayed'], $tool);
                $id = $confirmed['operation_id'];
                self::assertSame('succeeded', $this->data($this->alice->tool('mail_get_operation', ['operation_id' => $id]))['operation']['state']);
                self::assertFalse($this->bob->tool('mail_get_operation', ['operation_id' => $id])->ok());
            }
            self::assertSame($beforeReplay, [$snapshots->capture($owner, $account, $sourceId)['fingerprint'], $snapshots->capture($owner, $account, $destinationId)['fingerprint']]);
            [$tool, $confirmed] = $replays[0];
            ToolPermissionFixture::assign($owner, [$tool => 'deny']);
            try {
                self::assertFalse($this->alice->tool('mail_get_operation', ['operation_id' => $confirmed['operation_id']])->ok());
                self::assertFalse($this->alice->tool($tool, $confirmed)->ok());
            } finally { ToolPermissionFixture::assign($owner, [$tool => 'allow']); }
            self::assertSame($safe, MailActor::run($owner, 'c1-safety'));
            self::assertSame($heldHash, $this->heldHash($drafts, $draftId, $owner));
            $native = MailActor::run($owner, 'draft-lifecycle', ['draft_id' => $draftId]);
            self::assertFalse($native['due_for_sync']);
            self::assertSame($reply['draft']['in_reply_to'], $native['in_reply_to']);
            self::assertSame([base64_encode($bytes)], $native['attachment_bytes']);
            self::assertSame($bytes, $root->get($path)->getContent());
            self::assertSame('disabled', Server::get(\OCA\Mcp\Modules\Mail\Integration\MailCapabilities::class)->forUser($owner)['capabilities']['send']['status']);
        } finally {
            $cleanupReplays = [];
            if ($draftId !== null) { $this->write('mail_delete_draft', ['draft_id' => $draftId], $cleanupReplays); }
            if ($signatureLoaded) { $this->write('mail_write_signature', ['account_id' => $account, 'signature' => $signature], $cleanupReplays); }
            if ($tagId !== null) { $tags->delete($tags->getTagForUser($tagId, $owner)); }
            if ($root->nodeExists($path)) { $root->get($path)->delete(); }
            MailActor::run($owner, 'c3-cleanup', ['fixture' => $fixture]);
        }
    }

    private function heldHash(object $drafts, int $id, string $owner): string {
        $draft = $drafts->getMessage($id, $owner);
        self::assertTrue($draft->isFailed(), 'The local reply must remain held');
        return hash('sha256', json_encode($draft, JSON_THROW_ON_ERROR));
    }

    private function write(string $tool, array $args, array &$replays): array {
        $plan = $this->data($this->alice->tool($tool, $args));
        self::assertTrue($plan['requiresConfirmation'], $tool);
        $confirmed = $args + ['confirm' => true, 'plan_state' => $plan['plan_state'], 'operation_id' => $plan['operation_id']];
        $result = $this->data($this->alice->tool($tool, $confirmed));
        self::assertSame('succeeded', $result['operation']['state'], json_encode($result));
        $replays[] = [$tool, $confirmed];
        if (in_array($tool, ['mail_set_message_flag', 'mail_set_message_tag', 'mail_archive_message', 'mail_refresh_mailbox'], true)) {
            // Disposable-fixture clock substitute: release only this successful test's own admission slot.
            $q = Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
            $q->delete('mcp_mail_probe_slots')
                ->where($q->expr()->eq('scope_key', $q->createNamedParameter(hash('sha256', "mutation\0" . MailActor::ALICE . "\0" . $args['account_id']))))
                ->andWhere($q->expr()->eq('operation_id', $q->createNamedParameter($plan['operation_id'])))->executeStatement();
        }
        return $result;
    }

    private function data(ToolReply $reply): array {
        self::assertTrue($reply->ok(), $reply->describe());
        return $reply->structured() !== [] ? $reply->structured() : $reply->json();
    }
}
