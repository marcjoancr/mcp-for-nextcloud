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
final class McpMailLifecycleTest extends IntegrationTestCase {
    private const TOOLS=['mail_write_draft','mail_delete_draft','mail_read_message'];
    private array $toolSnapshot = [];
    private array $settings = [];
    private string $serviceBefore = '__missing__';
    private McpClient $alice;
    private McpClient $bob;

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



    public function testReplyParentAcrossNativeUiEditAndImapFlush():void {
        $id=$this->account();$alias=MailActor::run(MailActor::ALICE,'native-alias-fixture',['account_id'=>$id]);
        $source=$this->sourceMessage($id);$seed=MailActor::run(MailActor::ALICE,'c0-reply-seed',['message_id'=>$source]);
        $draftId=null;
        try{
            $args=['account_id'=>$id,'subject'=>'C0 reply '.bin2hex(random_bytes(6)),'body_plain'=>'Approved local body','reply_to_message_id'=>$source,'alias_id'=>$alias['alias_id']];
            $p=$this->data($this->alice->tool('mail_write_draft',$args));
            $saved=$this->data($this->alice->tool('mail_write_draft',$args+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]));
            $draftId=$saved['draft']['id'];
            $proof=MailActor::run(MailActor::ALICE,'c0-draft-proof',['draft_id'=>$draftId]);$draftId=null;
            self::assertTrue($proof['initially_held']);self::assertSame(!$proof['draft_only'],$proof['ui_released_hold']);
            self::assertSame('<shared-thread-root@mcp-it.example>',$proof['local_parent']);
            self::assertSame($proof['local_parent'],$proof['ui_parent']);self::assertSame($alias['alias_id'],$proof['local_alias_id']);
            self::assertCount(1,$proof['imap_drafts']);self::assertTrue($proof['imap_drafts'][0]['has_subject_header']);
            $qualifiedCandidate=getenv('MCP_IT_MAIL_GUARD')==='1';
            self::assertSame(!$qualifiedCandidate,$proof['imap_drafts'][0]['from_is_account'],'Native draft conversion must preserve an explicit owned sender.');
            self::assertSame($qualifiedCandidate,$proof['imap_drafts'][0]['has_parent_header']);self::assertSame($qualifiedCandidate,$proof['imap_drafts'][0]['has_references_header']);
            if($qualifiedCandidate){
                self::assertStringContainsString('d-alias@example.test',$proof['imap_drafts'][0]['from']);
                self::assertSame($proof['local_parent'],$proof['imap_drafts'][0]['in_reply_to']);
                self::assertSame($proof['local_parent'],$proof['imap_drafts'][0]['references']);
            }else{self::assertStringNotContainsString('d-alias@example.test',$proof['imap_drafts'][0]['from']);}
            self::assertTrue($proof['outgoing_unchanged']);self::assertTrue($proof['smtp_unchanged']);
        } finally {
            if($draftId!==null){$a=['draft_id'=>$draftId];$p=$this->data($this->alice->tool('mail_delete_draft',$a));$this->alice->tool('mail_delete_draft',$a+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]);}
            MailLifecycleActor::restoreReply($source,$seed);
            MailActor::run(MailActor::ALICE,'native-alias-fixture',$alias);
        }
    }
    public function testUidValidityChangeBeforeReadWriteSelectionRefusesMutation():void {
        $p=MailActor::run(MailActor::ALICE,'c0-selection-proof',['account_id'=>$this->account()]);
        self::assertTrue($p['generation_changed']);self::assertTrue($p['same_uid']);self::assertTrue($p['same_message_id']);
        self::assertTrue($p['native_store_after_reconnect']);self::assertTrue($p['duplicate_message_id_different_uid']);self::assertTrue($p['mcp_admission_refused']);
        self::assertTrue($p['outgoing_unchanged']);self::assertTrue($p['smtp_unchanged']);
        $known=getenv('MCP_IT_NC')==='35'||getenv('MCP_IT_MAIL_GUARD')==='1';
        self::assertSame(in_array(getenv('MCP_IT_MAIL_GUARD'),['1','legacy','v2'],true)?'verified':($known?'unsupported':'unverified'),$p['capabilities']['capabilities']['mutation_session']['status']);
        self::assertSame(in_array(getenv('MCP_IT_MAIL_GUARD'),['1','v2'],true)?'verified':($known?'blocked':'unverified'),$p['capabilities']['capabilities']['move']['status']);
        self::assertSame('disabled',$p['capabilities']['capabilities']['send']['status']);
    }
    public function testNativeCacheEventIsRequiredAndNoLifecycleProbeDispatchesOutboxOrSmtpSubmission():void {
        $proof=MailActor::run(MailActor::ALICE,'c0-cache-proof',['message_id'=>$this->sourceMessage($this->account())]);
        self::assertFalse($proof['original']);self::assertTrue($proof['remote_flagged']);
        self::assertFalse($proof['cache_after_low_level_mapper']);self::assertTrue($proof['cache_after_native_event']);
        self::assertTrue($proof['outgoing_unchanged']);self::assertTrue($proof['smtp_unchanged']);
    }
    private function sourceMessage(int $id):int {
        $q=$this->official()->getQueryBuilder();$r=$q->select('m.id')->from('mail_messages','m')->innerJoin('m','mail_mailboxes','b','m.mailbox_id = b.id')
            ->where($q->expr()->eq('b.account_id',$q->createNamedParameter($id)))->andWhere($q->expr()->eq('m.subject',$q->createNamedParameter('Seen message')))->executeQuery();
        try{return (int)$r->fetchOne();}finally{$r->closeCursor();}
    }
    private function account():int {return MailActor::run(MailActor::ALICE,'accounts')['accounts'][0]['id'];}
    private function data(ToolReply $reply):array {
        self::assertTrue($reply->ok(),$reply->describe());return $reply->structured()!==[]?$reply->structured():$reply->json();
    }
}
