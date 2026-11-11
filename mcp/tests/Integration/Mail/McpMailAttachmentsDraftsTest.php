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

/** Real HTTP tools, native Mail drafts, native Files writes and independent IMAP flag probes. */
#[Group('mail')]
final class McpMailAttachmentsDraftsTest extends IntegrationTestCase {
    private const TOOLS = ['mail_read_attachment', 'mail_save_attachment', 'mail_read_drafts', 'mail_write_draft', 'mail_delete_draft', 'mail_attach_files', 'mail_get_operation', 'mail_list_operations', 'mail_read_message', 'files_read'];
    private array $toolSnapshot = [];
    private array $settings = [];
    private string $serviceBefore = '__missing__';
    private McpClient $alice;
    private McpClient $bob;
    private ?MailFreshContentFixture $fresh = null;

    protected function setUp(): void {
        parent::setUp();
        if (getenv('MCP_IT_WITH_MAIL') !== '1') { self::markTestSkipped('Mail cell only'); }
        $config = Server::get(IConfig::class);
        $this->serviceBefore = $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '__missing__');
        $this->toolSnapshot = ToolPermissionFixture::capture([MailActor::ALICE, MailActor::BOB], self::TOOLS);
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        foreach ([MailActor::ALICE, MailActor::BOB] as $uid) {
            foreach ([GrantPolicy::ELIGIBLE_KEY, GrantPolicy::CONNECTED_KEY, 'grant_files_create'] as $key) {
                $this->settings[$uid][$key] = $config->getUserValue($uid, 'mcp', $key, '__missing__');
            }
            $policy->setEligible($uid, true);
            $policy->setConnected($uid, true);
            $policy->setGrant($uid, 'files', 'create', true);
            ToolPermissionFixture::assign($uid, array_fill_keys(self::TOOLS, 'allow'));
        }
        $this->alice = new McpClient(LoginFactory::basic(MailActor::ALICE));
        $this->bob = new McpClient(LoginFactory::basic(MailActor::BOB));
        if(Server::get(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class)->currentContentGeneration())$this->fresh=new MailFreshContentFixture(MailActor::ALICE,['Unread message'],'fresh-'.bin2hex(random_bytes(12)));
    }

    protected function tearDown(): void {
        try {
            if($this->fresh!==null){$this->fresh->close();$this->fresh=null;}
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

    public function testAttachmentReadsAndConfirmedFilesSavePreserveUnreadStateAndUserIsolation(): void {
        $message = $this->messageId($this->account(MailActor::ALICE), 'Unread message');
        $path = '/mcp-attachment-' . bin2hex(random_bytes(5)) . '.txt';
        $root = Server::get(IRootFolder::class)->getUserFolder(MailActor::ALICE);
        $aliceImap = $this->imap('alice');
        $bobImap = $this->imap('bob');
        try {
            $aliceState = $aliceImap->state(); $bobState = $bobImap->state();
            self::assertNotContains('\\Seen', $aliceState['INBOX']['flags'][3]);
            $list = $this->data($this->alice->tool('mail_read_attachment', ['message_id' => $message]));
            self::assertCount(1, $list['attachments']);
            $args = ['message_id' => $message, 'attachment_id' => $list['attachments'][0]['id']];
            $read = $this->data($this->alice->tool('mail_read_attachment', $args));
            $bytes = "Attachment bytes\r\nwith UTF-8: café\n";
            self::assertSame($bytes, base64_decode($read['content_base64'], true));
            $foreign = $this->bob->tool('mail_read_attachment', $args);
            $missing = $this->bob->tool('mail_read_attachment', ['message_id' => 987654321, 'attachment_id' => $args['attachment_id']]);
            self::assertTrue($foreign->isError(), $foreign->describe());
            self::assertSame($missing->fingerprint(), $foreign->fingerprint());
            $save = $args + ['path' => $path];
            $plan = $this->data($this->alice->tool('mail_save_attachment', $save));
            self::assertTrue($plan['requiresConfirmation']);
            self::assertFalse($root->nodeExists($path), 'a preview must not create a file');
            $wrongUser = $this->bob->tool('mail_save_attachment', $save + ['confirm' => true, 'operation_id' => $plan['operation_id'], 'plan_state' => $plan['plan_state']]);
            self::assertTrue($wrongUser->isError(), $wrongUser->describe());
            self::assertFalse($root->nodeExists($path));
            $saved = $this->data($this->alice->tool('mail_save_attachment', $save + ['confirm' => true, 'operation_id' => $plan['operation_id'], 'plan_state' => $plan['plan_state']]));
            self::assertSame('succeeded', $saved['operation']['state'], json_encode($saved, JSON_THROW_ON_ERROR));
            self::assertSame($path, $saved['file']['path']);
            self::assertSame($bytes, $root->get($path)->getContent());
            self::assertFalse(Server::get(IRootFolder::class)->getUserFolder(MailActor::BOB)->nodeExists($path));
            $collision = $this->alice->tool('mail_save_attachment', $save + ['confirm' => true, 'operation_id' => $plan['operation_id'], 'plan_state' => $plan['plan_state']]);
            self::assertTrue($this->data($collision)['operation']['replayed']);
            self::assertSame($bytes, $root->get($path)->getContent());
            self::assertSame($aliceState, $aliceImap->state(), 'attachment reads/saves changed Alice IMAP flags/modseq');
            self::assertSame($bobState, $bobImap->state(), 'attachment reads/saves changed Bob IMAP flags/modseq');
        } finally {
            $aliceImap->close(); $bobImap->close();
            if ($root->nodeExists($path)) { $root->get($path)->delete(); }
        }
    }

    public function testDraftLifecycleRequiresMatchingConfirmationAndNeverCrossesUsersOrSends(): void {
        $account = $this->account(MailActor::ALICE);
        $subject = 'Draft proof ' . bin2hex(random_bytes(5));
        $args = ['account_id' => $account, 'subject' => $subject, 'body_plain' => 'Keep this body.', 'to' => [['email' => 'recipient@example.com']]];
        $aliceImap = $this->imap('alice'); $bobImap = $this->imap('bob');
        $draftId = null;
        try {
            $before = [$aliceImap->state(), $bobImap->state()];
            $plan = $this->data($this->alice->tool('mail_write_draft', $args));
            self::assertTrue($plan['requiresConfirmation']);
            self::assertFalse(in_array($subject, array_column($this->data($this->alice->tool('mail_read_drafts'))['drafts'], 'subject'), true));
            $foreignAccount = $this->bob->tool('mail_write_draft', $args + ['confirm' => true, 'operation_id' => $plan['operation_id'], 'plan_state' => $plan['plan_state']]);
            self::assertTrue($foreignAccount->isError(), $foreignAccount->describe());
            $saved = $this->data($this->alice->tool('mail_write_draft', $args + ['confirm' => true, 'operation_id' => $plan['operation_id'], 'plan_state' => $plan['plan_state']]));
            $draftId = $saved['draft']['id'];
            self::assertSame($subject, $saved['draft']['subject']);
            self::assertSame('Keep this body.', $this->data($this->alice->tool('mail_read_drafts', ['draft_id' => $draftId]))['draft']['body_plain']);
            $foreign = $this->bob->tool('mail_read_drafts', ['draft_id' => $draftId]);
            self::assertTrue($foreign->isError(), $foreign->describe());
            self::assertSame($this->bob->tool('mail_read_drafts', ['draft_id' => 987654321])->fingerprint(), $foreign->fingerprint());
            self::assertTrue($this->bob->tool('mail_delete_draft', ['draft_id' => $draftId])->isError());
            $edit = ['account_id' => $account, 'draft_id' => $draftId, 'subject' => $subject . ' edited'];
            $editPlan = $this->data($this->alice->tool('mail_write_draft', $edit));
            $edited = $this->data($this->alice->tool('mail_write_draft', $edit + ['confirm' => true, 'operation_id' => $editPlan['operation_id'], 'plan_state' => $editPlan['plan_state']]));
            self::assertSame('Keep this body.', $edited['draft']['body_plain']);
            self::assertSame($saved['draft']['recipients'], $edited['draft']['recipients']);
            $stale = $this->data($this->alice->tool('mail_write_draft', $edit + ['confirm' => true, 'operation_id' => $editPlan['operation_id'], 'plan_state' => $editPlan['plan_state']]));
            self::assertTrue($stale['operation']['replayed'], 'the same confirmed edit must not execute twice');
            $deletePlan = $this->data($this->alice->tool('mail_delete_draft', ['draft_id' => $draftId]));
            self::assertTrue($deletePlan['requiresConfirmation']);
            self::assertSame($draftId, $this->data($this->alice->tool('mail_read_drafts', ['draft_id' => $draftId]))['draft']['id']);
            $deleted = $this->data($this->alice->tool('mail_delete_draft', ['draft_id' => $draftId, 'confirm' => true, 'operation_id' => $deletePlan['operation_id'], 'plan_state' => $deletePlan['plan_state']]));
            self::assertSame($draftId, $deleted['deleted_draft_id']);
            self::assertTrue($this->alice->tool('mail_read_drafts', ['draft_id' => $draftId])->isError());
            $draftId = null;
            self::assertSame($before, [$aliceImap->state(), $bobImap->state()], 'local draft management must not send or modify IMAP messages');
        } finally {
            if ($draftId !== null) {
                $plan = $this->data($this->alice->tool('mail_delete_draft', ['draft_id' => $draftId]));
                $this->alice->tool('mail_delete_draft', ['draft_id' => $draftId, 'confirm' => true, 'operation_id' => $plan['operation_id'], 'plan_state' => $plan['plan_state']]);
            }
            $aliceImap->close(); $bobImap->close();
        }
    }

    public function testReplyFilesReceiptsAndNativeDraftRetention(): void {
        $account = $this->account(MailActor::ALICE);
        $message = $this->messageId($account, 'Unread message');
        $root = Server::get(IRootFolder::class)->getUserFolder(MailActor::ALICE);
        $path = '/reply-file-' . bin2hex(random_bytes(5)) . '.txt';
        $bytes = "Approved Files bytes\n";
        $root->newFile(ltrim($path, '/'), $bytes);
        $draftId = null;
        try {
            $args = ['account_id' => $account, 'subject' => 'Re: Unread message', 'body_plain' => 'Reply text',
                'reply_to_message_id' => $message, 'reply_mode' => 'reply_all', 'add_files' => [$path]];
            $contract=MailActor::run(MailActor::ALICE,'composition-contract',['arguments'=>$args]);
            self::assertSame([],$contract['errors'],json_encode($contract));
            $plan = $this->data($this->alice->tool('mail_write_draft', $args));
            self::assertNotEmpty($plan['operation_id']);
            $confirm = $args + ['confirm' => true, 'plan_state' => $plan['plan_state'], 'operation_id' => $plan['operation_id']];
            $saved = $this->data($this->alice->tool('mail_write_draft', $confirm));
            self::assertSame('succeeded', $saved['operation']['state'], json_encode($saved));
            $draftId = $saved['draft']['id'];
            self::assertTrue($saved['draft']['sync_held']);
            self::assertNotEmpty($saved['draft']['in_reply_to']);
            self::assertCount(1, $saved['draft']['attachments']);
            $attachmentId = $saved['draft']['attachments'][0]['id'];
            $again = $this->data($this->alice->tool('mail_write_draft', $confirm));
            self::assertTrue($again['operation']['replayed']);
            self::assertSame($draftId, $again['operation']['resource_refs']['draft_id']);
            $receipt = $this->data($this->alice->tool('mail_get_operation', ['operation_id' => $plan['operation_id']]));
            self::assertSame('succeeded', $receipt['operation']['state']);
            self::assertStringNotContainsString('Reply text', json_encode($receipt));
            self::assertTrue($this->bob->tool('mail_get_operation', ['operation_id' => $plan['operation_id']])->isError());

            $native = MailActor::run(MailActor::ALICE, 'draft-lifecycle', ['draft_id' => $draftId]);
            self::assertFalse($native['due_for_sync']);
            self::assertSame($saved['draft']['in_reply_to'], $native['in_reply_to']);
            self::assertSame(base64_encode($bytes), $native['attachment_bytes'][0]);
            $cleanup=MailActor::run(MailActor::ALICE,'cleanup-contract',['attachment_id'=>$attachmentId]);
            $atomicCleanup=getenv('MCP_IT_MAIL_GUARD')==='1'&&\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::DRAFT_IDENTITY_QUALIFIED;
            self::assertSame($atomicCleanup,$cleanup['orphan_missing']);
            self::assertTrue($cleanup['uncertain_attachment_exists']);
            self::assertSame($draftId,$cleanup['associated_draft_id']);
            self::assertSame(['orphan'=>$atomicCleanup?'expired':'cleaning','already_removed'=>$atomicCleanup?'expired':'cleaning','uncertain'=>'uncertain','associated'=>'expired'],$cleanup['states']);

            $edit = ['account_id' => $account, 'draft_id' => $draftId, 'subject' => 'Re: Unread message', 'body_plain' => 'Edited reply'];
            $p = $this->data($this->alice->tool('mail_write_draft', $edit));
            $updated = $this->data($this->alice->tool('mail_write_draft', $edit + ['confirm' => true, 'plan_state' => $p['plan_state'], 'operation_id' => $p['operation_id']]));
            self::assertSame($attachmentId, $updated['draft']['attachments'][0]['id']);
            self::assertSame($saved['draft']['in_reply_to'], $updated['draft']['in_reply_to']);
            $remove = ['account_id' => $account, 'draft_id' => $draftId, 'remove_attachment_ids' => [$attachmentId]];
            $p = $this->data($this->alice->tool('mail_attach_files', $remove));
            $updated = $this->data($this->alice->tool('mail_attach_files', $remove + ['confirm' => true, 'plan_state' => $p['plan_state'], 'operation_id' => $p['operation_id']]));
            self::assertSame([], $updated['draft']['attachments']);
            self::assertSame($bytes, $root->get(ltrim($path, '/'))->getContent());
            $nativeEdit = MailActor::run(MailActor::ALICE, 'native-draft-update', ['draft_id' => $draftId]);
            self::assertSame($saved['draft']['in_reply_to'], $nativeEdit['in_reply_to']);
            $qualified=getenv('MCP_IT_MAIL_GUARD')==='1'&&\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::DRAFT_IDENTITY_QUALIFIED;
            self::assertSame($qualified,$nativeEdit['draft_only'],'Qualified HTTP writes must persist draft-only protection.');
            self::assertSame($qualified,$nativeEdit['sync_held'],'Protected native updates keep the hold; stock retains its characterized behavior.');
            self::assertSame(!$qualified,$nativeEdit['due_for_sync']);
        } finally {
            if ($draftId !== null) {
                $p = $this->data($this->alice->tool('mail_delete_draft', ['draft_id' => $draftId]));
                $this->alice->tool('mail_delete_draft', ['draft_id' => $draftId, 'confirm' => true, 'plan_state' => $p['plan_state'], 'operation_id' => $p['operation_id']]);
            }
            if ($root->nodeExists(ltrim($path, '/'))) $root->get(ltrim($path, '/'))->delete();
        }
    }

    private function account(string $uid): int {
        $query = $this->official()->getQueryBuilder();
        $result = $query->select('id')->from('mail_accounts')->where($query->expr()->eq('user_id', $query->createNamedParameter($uid)))->executeQuery();
        try { $id = (int)$result->fetchOne(); self::assertGreaterThan(0, $id); return $id; }
        finally { $result->closeCursor(); }
    }
    private function messageId(int $account, string $subject): int {
        if($this->fresh!==null&&isset($this->fresh->messages[$subject]))return $this->fresh->messages[$subject];
        $query = $this->official()->getQueryBuilder();
        $result = $query->select('m.id')->from('mail_messages', 'm')->innerJoin('m', 'mail_mailboxes', 'b', 'm.mailbox_id = b.id')
            ->where($query->expr()->eq('b.account_id', $query->createNamedParameter($account, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->eq('m.subject', $query->createNamedParameter($subject)))->executeQuery();
        try { $id = (int)$result->fetchOne(); self::assertGreaterThan(0, $id); return $id; }
        finally { $result->closeCursor(); }
    }
    private function data(ToolReply $reply): array {
        self::assertTrue($reply->ok(), $reply->describe());
        return $reply->structured() !== [] ? $reply->structured() : $reply->json();
    }
    private function imap(string $uid): ImapProbe {
        return new ImapProbe((string)getenv('MCP_IT_IMAP_HOST'), (int)getenv('MCP_IT_IMAP_PORT'), $uid, (string)getenv('MCP_IT_IMAP_PASSWORD'));
    }
}
