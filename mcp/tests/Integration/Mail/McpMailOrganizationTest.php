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
final class McpMailOrganizationTest extends IntegrationTestCase {
    private const TOOLS = ['mail_create_mailbox','mail_rename_mailbox','mail_set_mailbox_subscription','mail_configure_mailboxes','mail_list_aliases','mail_list_tags','mail_read_message','mail_read_attachment','mail_write_draft','mail_read_drafts','mail_delete_draft','mail_attach_files','files_read','mail_get_operation','mail_list_operations'];
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


    /** Read-only wait: keep the real five/account/minute budget when C1 ran earlier in this same disposable cell. */
    private function waitForMutationWindow(int $account):void {
        $q=$this->official()->getQueryBuilder();$r=$q->select('expires_at')->from('mcp_mail_probe_slots')->where($q->expr()->eq('scope_key',$q->createNamedParameter(hash('sha256',"mutation\0".MailActor::ALICE."\0".$account))))->executeQuery();try{$times=array_column($r->fetchAll(),'expires_at');}finally{$r->closeCursor();}
        $deadline=$times===[]?time():max(array_map('intval',$times))+1;self::assertLessThanOrEqual(61,$deadline-time());while(time()<$deadline)usleep(100000);
    }
    public function testFoldersAndRolePatchesAreBoundedConfirmedAndReplayReadOnly():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('V3 native guard only');
        $account=MailActor::run(MailActor::ALICE,'accounts')['accounts'][0]['id'];$this->waitForMutationWindow($account);$f=MailActor::run(MailActor::ALICE,'c3-fixture',['account_id'=>$account]);$safe=MailActor::run(MailActor::ALICE,'c1-safety');$child=null;
        try{
            $before=MailActor::run(MailActor::ALICE,'native-settings-proof',['account_id'=>$account]);
            $args=['account_id'=>$account,'parent_mailbox_id'=>$f['boxes']['source'],'name'=>'ReviewedChild'];$p=$this->data($this->alice->tool('mail_create_mailbox',$args));self::assertTrue($p['requiresConfirmation']);
            $confirm=$args+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];$r=$this->data($this->alice->tool('mail_create_mailbox',$confirm));self::assertSame('succeeded',$r['operation']['state'],json_encode($r));$child=$r['operation']['mutation']['cache']['mailboxes'][0]['id'];
            self::assertTrue($this->data($this->alice->tool('mail_create_mailbox',$confirm))['operation']['replayed']);
            self::assertFalse($this->alice->tool('mail_set_mailbox_subscription',['account_id'=>$account,'mailbox_id'=>$child,'subscribed'=>true])->ok());
            $synced=$f;$synced['boxes']['created']=$child;MailActor::run(MailActor::ALICE,'c3-inspect',['fixture'=>$synced,'mailbox_id'=>$child,'sync'=>true]);
            self::assertFalse($this->bob->tool('mail_set_mailbox_subscription',['account_id'=>$account,'mailbox_id'=>$child,'subscribed'=>true])->ok());
            foreach([['mail_set_mailbox_subscription',['account_id'=>$account,'mailbox_id'=>$child,'subscribed'=>true]],['mail_rename_mailbox',['account_id'=>$account,'mailbox_id'=>$f['boxes']['source'],'name'=>'mcp-c3-renamed-'.bin2hex(random_bytes(6))]],['mail_configure_mailboxes',['account_id'=>$account,'roles'=>['archive_mailbox_id'=>$child]]]] as [$tool,$a]){
                $p=$this->data($this->alice->tool($tool,$a));$c=$a+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];$w=$this->data($this->alice->tool($tool,$c));self::assertSame('succeeded',$w['operation']['state'],json_encode($w));self::assertTrue($this->data($this->alice->tool($tool,$c))['operation']['replayed']);
                $receipt=$this->data($this->alice->tool('mail_get_operation',['operation_id'=>$p['operation_id']]));self::assertSame('succeeded',$receipt['operation']['state']);
                ToolPermissionFixture::assign(MailActor::ALICE,[$tool=>'deny']);self::assertFalse($this->alice->tool('mail_get_operation',['operation_id'=>$p['operation_id']])->ok());ToolPermissionFixture::assign(MailActor::ALICE,[$tool=>'allow']);$this->alice=new McpClient(LoginFactory::basic(MailActor::ALICE));
            }
            self::assertSame($before,MailActor::run(MailActor::ALICE,'native-settings-proof',['account_id'=>$account]));self::assertSame($safe,MailActor::run(MailActor::ALICE,'c1-safety'));
        }finally{
            if($child!==null){$a=\OCP\Server::get('OCA\\Mail\\Service\\AccountService')->find(MailActor::ALICE,$account);$manager=\OCP\Server::get('OCA\\Mail\\Service\\MailManager');$manager->deleteMailbox($a,$manager->getMailbox(MailActor::ALICE,$child));}
            MailActor::run(MailActor::ALICE,'c3-cleanup',['fixture'=>$f]);
        }
    }
    public function testForwardingIdentitiesTagsAndDraftFiltersKeepEverythingUnsent():void {
        $account=MailActor::run(MailActor::ALICE,'accounts')['accounts'][0]['id'];$q=$this->official()->getQueryBuilder();$r=$q->select('m.id')->from('mail_messages','m')->innerJoin('m','mail_mailboxes','b','m.mailbox_id=b.id')->where($q->expr()->eq('b.account_id',$q->createNamedParameter($account)))->andWhere($q->expr()->eq('m.subject',$q->createNamedParameter('Unread message')))->executeQuery();try{$message=(int)$r->fetchOne();}finally{$r->closeCursor();}
        $alias=MailActor::run(MailActor::ALICE,'native-alias-fixture',['account_id'=>$account]);$seed=MailActor::run(MailActor::ALICE,'c0-reply-seed',['message_id'=>$message]);$safe=MailActor::run(MailActor::ALICE,'c1-safety');$draft=null;$root=Server::get(IRootFolder::class)->getUserFolder(MailActor::ALICE);$path='/c4-file-'.bin2hex(random_bytes(5)).'.txt';$file=$root->newFile($path,'Files forward snapshot');
        try{
            $identities=$this->data($this->alice->tool('mail_list_aliases',['account_id'=>$account]));self::assertContains($alias['alias_id'],array_column($identities['identities'],'alias_id'));self::assertFalse($this->bob->tool('mail_list_aliases',['account_id'=>$account])->ok());$this->data($this->alice->tool('mail_list_tags'));
            $list=$this->data($this->alice->tool('mail_read_attachment',['message_id'=>$message]));$part=$list['attachments'][0]['id'];$original=$this->data($this->alice->tool('mail_read_attachment',['message_id'=>$message,'attachment_id'=>$part]));
            $a=['account_id'=>$account,'subject'=>'C4 forward '.bin2hex(random_bytes(6)),'forward_message_id'=>$message,'forward_attachment_ids'=>[$part],'alias_id'=>$alias['alias_id'],'add_files'=>[$path],'to'=>[['email'=>'reviewed@example.test']]];
            $p=$this->data($this->alice->tool('mail_write_draft',$a));self::assertSame([],$p['after']['bcc']);self::assertNull($p['after']['in_reply_to']);self::assertStringContainsString('Body of Unread message.',$p['after']['body_plain']);self::assertCount(2,$p['after']['attachments']);
            $c=$a+['confirm'=>true,'operation_id'=>$p['operation_id'],'plan_state'=>$p['plan_state']];$saved=$this->data($this->alice->tool('mail_write_draft',$c));$draft=$saved['draft']['id'];self::assertSame('succeeded',$saved['operation']['state']);self::assertSame($alias['alias_id'],$saved['draft']['alias_id']);self::assertNull($saved['draft']['in_reply_to']);self::assertTrue($this->data($this->alice->tool('mail_write_draft',$c))['operation']['replayed']);
            $native=MailActor::run(MailActor::ALICE,'draft-lifecycle',['draft_id'=>$draft]);self::assertContains($original['content_base64'],$native['attachment_bytes']);self::assertContains(base64_encode('Files forward snapshot'),$native['attachment_bytes']);
            $filtered=$this->data($this->alice->tool('mail_read_drafts',['account_id'=>$account,'subject_contains'=>$a['subject']]));self::assertCount(1,$filtered['drafts']);self::assertSame($draft,$filtered['drafts'][0]['id']);
            $manual=['account_id'=>$account,'subject'=>'Manual forward','forward_message_id'=>$message,'body_plain'=>'Explicit final text'];$p=$this->data($this->alice->tool('mail_write_draft',$manual));self::assertSame('Explicit final text',$p['after']['body_plain']);self::assertSame([],$p['after']['attachments']);
        }finally{
            if($draft!==null){$a=['draft_id'=>$draft];$p=$this->data($this->alice->tool('mail_delete_draft',$a));$this->alice->tool('mail_delete_draft',$a+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]);}
            $file->delete();MailLifecycleActor::restoreReply($message,$seed);MailActor::run(MailActor::ALICE,'native-alias-fixture',$alias);
        }
        self::assertSame($safe,MailActor::run(MailActor::ALICE,'c1-safety'));
    }
    private function data(ToolReply $reply):array {self::assertTrue($reply->ok(),$reply->describe());return $reply->structured()!==[]?$reply->structured():$reply->json();}
}
