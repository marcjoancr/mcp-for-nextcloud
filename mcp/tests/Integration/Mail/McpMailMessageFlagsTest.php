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
final class McpMailMessageFlagsTest extends IntegrationTestCase {
    private const TOOLS = ['mail_set_message_flag','mail_get_operation','mail_list_operations'];
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


    public function testApprovedFlagUpdatesRemoteAndCacheWithReadOnlyDurableStatus():void {
        $q=$this->official()->getQueryBuilder();$r=$q->select('id')->from('mail_accounts')->where($q->expr()->eq('user_id',$q->createNamedParameter(MailActor::ALICE)))->executeQuery();
        try{$account=(int)$r->fetchOne();}finally{$r->closeCursor();}
        $q=$this->official()->getQueryBuilder();$r=$q->select('m.id')->from('mail_messages','m')->innerJoin('m','mail_mailboxes','b','m.mailbox_id=b.id')->where($q->expr()->eq('b.account_id',$q->createNamedParameter($account)))->andWhere($q->expr()->eq('b.name',$q->createNamedParameter('INBOX')))->orderBy('m.id','ASC')->setMaxResults(1)->executeQuery();
        try{$message=(int)$r->fetchOne();}finally{$r->closeCursor();}
        $args=['account_id'=>$account,'message_id'=>$message,'flag'=>'flagged','value'=>true];
        $safety=MailActor::run(MailActor::ALICE,'c1-safety');
        $before=MailActor::run(MailActor::ALICE,'c1-read-flag',['message_id'=>$message]);
        $p=$this->data($this->alice->tool('mail_set_message_flag',$args));
        self::assertTrue($p['requiresConfirmation']);self::assertSame($before,MailActor::run(MailActor::ALICE,'c1-read-flag',['message_id'=>$message]));
        $confirmed=$args+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];
        try {
            $written=$this->data($this->alice->tool('mail_set_message_flag',$confirmed));
            self::assertSame('succeeded',$written['operation']['state'],json_encode($written));
            self::assertSame('completed',$written['operation']['mutation']['phase']);
            $after=MailActor::run(MailActor::ALICE,'c1-read-flag',['message_id'=>$message]);self::assertTrue($after['remote']);self::assertTrue($after['cached']);
            $again=$this->data($this->alice->tool('mail_set_message_flag',$confirmed));self::assertTrue($again['operation']['replayed']);
            $status=$this->data($this->alice->tool('mail_get_operation',['operation_id'=>$p['operation_id']]));self::assertSame('completed',$status['operation']['mutation']['phase']);
            self::assertSame($after,MailActor::run(MailActor::ALICE,'c1-read-flag',['message_id'=>$message]));
            self::assertSame($safety,MailActor::run(MailActor::ALICE,'c1-safety'));
            $cache=MailActor::run(MailActor::ALICE,'c1-cache-proof',['message_id'=>$message]);
            self::assertTrue($cache['cache_stage_refused']);self::assertTrue($cache['remote_proof_retained']);self::assertTrue($cache['old_cache_untouched']);
            self::assertFalse($this->bob->tool('mail_set_message_flag',$args)->ok());
            ToolPermissionFixture::assign(MailActor::ALICE,['mail_set_message_flag'=>'deny']);
            self::assertFalse($this->alice->tool('mail_set_message_flag',$confirmed)->ok());self::assertFalse($this->alice->tool('mail_get_operation',['operation_id'=>$p['operation_id']])->ok());
        } finally {
            ToolPermissionFixture::assign(MailActor::ALICE,['mail_set_message_flag'=>'allow']);$this->alice=new McpClient(LoginFactory::basic(MailActor::ALICE));
            $restore=array_replace($args,['value'=>$before['remote']]);$p=$this->data($this->alice->tool('mail_set_message_flag',$restore));
            $this->data($this->alice->tool('mail_set_message_flag',$restore+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]));
        }
    }
    private function data(ToolReply $reply):array {self::assertTrue($reply->ok(),$reply->describe());return $reply->structured()!==[]?$reply->structured():$reply->json();}
}
