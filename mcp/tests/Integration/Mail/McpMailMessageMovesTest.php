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
final class McpMailMessageMovesTest extends IntegrationTestCase {
    private const TOOLS = ['mail_move_message','mail_archive_message','mail_trash_message','mail_restore_message','mail_get_operation','mail_list_operations'];
    private array $toolSnapshot = [];
    private array $settings = [];
    private string $serviceBefore = '__missing__';
    private McpClient $alice;
    private McpClient $bob;

    protected function setUp(): void {
        parent::setUp();
        if (getenv('MCP_IT_WITH_MAIL') !== '1' || !in_array(getenv('MCP_IT_MAIL_GUARD'), ['1', 'v2'], true)) { self::markTestSkipped('Mail cell only'); }
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
        $this->alice = new McpClient(LoginFactory::basic(MailActor::BOB));
        $this->bob = new McpClient(LoginFactory::basic(MailActor::ALICE));
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


    public function testFourApprovedMovesAreExactAndReplayAndStatusAreReadOnly():void {
        $account=MailActor::run(MailActor::BOB,'accounts')['accounts'][0]['id'];$f=MailActor::run(MailActor::BOB,'c3-fixture',['account_id'=>$account]);$safe=MailActor::run(MailActor::BOB,'c1-safety');
        try {
            $inspect=static fn(int $id,bool $sync=false):array=>MailActor::run(MailActor::BOB,'c3-inspect',['fixture'=>$f,'mailbox_id'=>$id,'sync'=>$sync]);
            $a=['account_id'=>$account,'message_id'=>$f['messages'][0],'destination_mailbox_id'=>$f['boxes']['destination']];
            self::assertFalse($this->bob->tool('mail_move_message',$a)->ok());
            self::assertFalse($this->alice->tool('mail_move_message',array_replace($a,['destination_mailbox_id'=>$f['boxes']['source']]))->ok());
            self::assertFalse($this->alice->tool('mail_restore_message',$a)->ok());
            $q=$this->official()->getQueryBuilder();$foreignAccount=MailActor::run(MailActor::ALICE,'accounts')['accounts'][0]['id'];$q->select('id')->from('mail_mailboxes')->where($q->expr()->eq('account_id',$q->createNamedParameter($foreignAccount)))->andWhere($q->expr()->eq('name',$q->createNamedParameter('INBOX')));$rows=$q->executeQuery();try{$foreignFolder=(int)$rows->fetchOne();}finally{$rows->closeCursor();}
            self::assertFalse($this->alice->tool('mail_move_message',array_replace($a,['destination_mailbox_id'=>$foreignFolder]))->ok());

            $roleArgs=['account_id'=>$account,'message_id'=>$f['messages'][1]];$old=$this->data($this->alice->tool('mail_archive_message',$roleArgs));
            $same=$inspect($f['boxes']['source']);MailActor::run(MailActor::BOB,'c3-role',['fixture'=>$f,'role'=>'archive','mailbox_id'=>$f['boxes']['destination']]);
            $changed=$this->data($this->alice->tool('mail_archive_message',$roleArgs+['confirm'=>true,'plan_state'=>$old['plan_state'],'operation_id'=>$old['operation_id']]));
            self::assertTrue($changed['requiresConfirmation']);self::assertSame('failed_before_effect',$this->data($this->alice->tool('mail_get_operation',['operation_id'=>$old['operation_id']]))['operation']['state']);self::assertSame($f['boxes']['destination'],$changed['destination']['id']);self::assertSame($same,$inspect($f['boxes']['source']));
            MailActor::run(MailActor::BOB,'c3-role',['fixture'=>$f,'role'=>'archive','mailbox_id'=>$f['boxes']['archive']]);
            foreach(['move','archive','trash','restore','move_to_trash'] as $i=>$action){
                $tool='mail_'.($action==='move_to_trash'?'move':$action).'_message';
                if($action==='restore'){
                    $trash=$inspect($f['boxes']['trash'],true);self::assertCount(1,$trash['cached']);
                    $args=['account_id'=>$account,'message_id'=>$trash['cached'][0]['id'],'destination_mailbox_id'=>$f['boxes']['source']];
                    self::assertFalse($this->alice->tool('mail_trash_message',['account_id'=>$account,'message_id'=>$trash['cached'][0]['id']])->ok());
                }else{
                    $args=['account_id'=>$account,'message_id'=>$f['messages'][$i===4?3:$i]];
                    if($action==='move')$args['destination_mailbox_id']=$f['boxes']['destination'];
                    if($action==='move_to_trash')$args['destination_mailbox_id']=$f['boxes']['trash'];
                }
                $p=$this->data($this->alice->tool($tool,$args));self::assertTrue($p['requiresConfirmation']);
                $confirmed=$args+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];
                $r=$this->data($this->alice->tool($tool,$confirmed));self::assertSame('succeeded',$r['operation']['state'],json_encode($r));
                self::assertSame('completed',$r['operation']['mutation']['phase']);self::assertSame('moved',$r['operation']['mutation']['remote']['outcome']);
                self::assertSame('native_sync_pending',$r['operation']['mutation']['cache']['destination_state']);
                $after=$inspect($p['destination']['id']);$again=$this->data($this->alice->tool($tool,$confirmed));self::assertTrue($again['operation']['replayed']);
                $status=$this->data($this->alice->tool('mail_get_operation',['operation_id'=>$p['operation_id']]));self::assertSame('succeeded',$status['operation']['state']);
                self::assertSame($after,$inspect($p['destination']['id']));
                $public=json_encode($status);self::assertStringNotContainsString('token_hash',$public);self::assertStringNotContainsString('profile_revision',$public);
                ToolPermissionFixture::assign(MailActor::BOB,[$tool=>'deny']);self::assertFalse($this->alice->tool('mail_get_operation',['operation_id'=>$p['operation_id']])->ok());
                ToolPermissionFixture::assign(MailActor::BOB,[$tool=>'allow']);$this->alice=new McpClient(LoginFactory::basic(MailActor::BOB));
            }
            self::assertCount(2,$inspect($f['boxes']['source'])['remote']);
            self::assertSame($safe,MailActor::run(MailActor::BOB,'c1-safety'));
        }finally{MailActor::run(MailActor::BOB,'c3-cleanup',['fixture'=>$f]);}
    }
    private function data(ToolReply $reply):array {self::assertTrue($reply->ok(),$reply->describe());return $reply->structured()!==[]?$reply->structured():$reply->json();}
}
