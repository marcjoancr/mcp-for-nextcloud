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

/** Real native tag/index boundary and shared DB journal transaction, with unchanged mail safety state. */
#[Group('mail')]
final class McpMailC5TagAssignmentTest extends IntegrationTestCase {
    private const TOOLS = ['mail_create_tag','mail_list_tags','mail_set_message_tag','mail_get_operation','mail_list_operations','mail_write_draft','mail_read_message','mail_delete_draft'];
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



    public function testReviewedAddRemoveNoopReplayAndSourceRevocationAreExactAndKeepReplyHeld():void {
        $owner=MailActor::ALICE;$account=MailActor::run($owner,'accounts')['accounts'][0]['id'];$f=MailActor::run($owner,'c3-fixture',['account_id'=>$account]);$tagId=null;$draftId=null;$name='C5 HTTP '.bin2hex(random_bytes(6));$tagMap=Server::get('OCA\\Mail\\Db\\TagMapper');$nativeManager=Server::get('OCA\\Mail\\Service\\MailManager');
        try {
            $create=['name'=>$name,'color'=>'#AABBCC'];$p=$this->data($this->alice->tool('mail_create_tag',$create));$created=$this->data($this->alice->tool('mail_create_tag',$create+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]));$tagId=$created['tag']['tag_id'];
            $reply=['account_id'=>$account,'reply_to_message_id'=>$f['messages'][0],'reply_mode'=>'reply','subject'=>'C5 held '.bin2hex(random_bytes(5)),'body_plain'=>'Keep this local reply held.'];$p=$this->data($this->alice->tool('mail_write_draft',$reply));$draft=$this->data($this->alice->tool('mail_write_draft',$reply+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]));$draftId=$draft['draft']['id'];$drafts=Server::get('OCA\\Mail\\Service\\DraftsService');$held=$drafts->getMessage($draftId,$owner);self::assertTrue($held->isFailed());$heldHash=hash('sha256',json_encode($held,JSON_THROW_ON_ERROR));$safe=MailActor::run($owner,'c1-safety');
            $args=['account_id'=>$account,'message_id'=>$f['messages'][0],'tag_id'=>$tagId,'value'=>true];self::assertFalse($this->bob->tool('mail_set_message_tag',$args)->ok());
            $bob=Server::get('OCA\\Mail\\Service\\MailManager')->createTag('C5 foreign '.bin2hex(random_bytes(5)),'#123456',MailActor::BOB);
            try{self::assertFalse($this->alice->tool('mail_set_message_tag',array_replace($args,['tag_id'=>$bob->getId()]))->ok());}finally{$tagMap->delete($bob);}
            // Unknown legacy provenance is a refusal, not an empty tag state or implicit refresh.
            $db=Server::get(\OCP\IDBConnection::class);$message=$nativeManager->getMessage($owner,$args['message_id']);$generation=$message->getTagCacheGeneration();$q=$db->getQueryBuilder();$q->update('mail_messages')->set('tag_cache_generation',$q->createNamedParameter(null))->where($q->expr()->eq('id',$q->createNamedParameter($message->getId())))->executeStatement();
            try{self::assertFalse($this->alice->tool('mail_set_message_tag',$args)->ok());}finally{$q=$db->getQueryBuilder();$q->update('mail_messages')->set('tag_cache_generation',$q->createNamedParameter($generation))->where($q->expr()->eq('id',$q->createNamedParameter($message->getId())))->executeStatement();}
            $old=$this->data($this->alice->tool('mail_set_message_tag',$args));$tag=$tagMap->getTagForUser($tagId,$owner);$tag->setDisplayName($name.' renamed');$tagMap->update($tag);$changed=$this->data($this->alice->tool('mail_set_message_tag',$args+['confirm'=>true,'plan_state'=>$old['plan_state'],'operation_id'=>$old['operation_id']]));self::assertTrue($changed['requiresConfirmation']);
            foreach([true,true,false]as$value){$args['value']=$value;$p=$this->data($this->alice->tool('mail_set_message_tag',$args));self::assertTrue($p['requiresConfirmation']);self::assertSame($name.' renamed',$p['tag']['name']);$confirmed=$args+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];$result=$this->data($this->alice->tool('mail_set_message_tag',$confirmed));self::assertSame('succeeded',$result['operation']['state'],json_encode($result));self::assertSame($tagId,$result['operation']['mutation']['cache']['tag_id']);self::assertSame($value,$result['operation']['mutation']['cache']['value']);$this->releaseOwnSlot($p['operation_id'],$owner,$account);
                $again=$this->data($this->alice->tool('mail_set_message_tag',$confirmed));self::assertTrue($again['operation']['replayed']);$this->data($this->alice->tool('mail_get_operation',['operation_id'=>$p['operation_id']]));self::assertFalse($this->bob->tool('mail_get_operation',['operation_id'=>$p['operation_id']])->ok());
                $exact=Server::get('OCA\\Mail\\Db\\ExactTagAssociations');self::assertSame($value,$exact->has($owner,$args['message_id'],$tagId));self::assertFalse($exact->has($owner,$f['messages'][1],$tagId));
                ToolPermissionFixture::assign($owner,['mail_set_message_tag'=>'deny']);self::assertFalse($this->alice->tool('mail_get_operation',['operation_id'=>$p['operation_id']])->ok());self::assertFalse($this->alice->tool('mail_set_message_tag',$confirmed)->ok());ToolPermissionFixture::assign($owner,['mail_set_message_tag'=>'allow']);
            }
            self::assertSame($safe,MailActor::run($owner,'c1-safety'));$after=$drafts->getMessage($draftId,$owner);self::assertTrue($after->isFailed());self::assertSame($heldHash,hash('sha256',json_encode($after,JSON_THROW_ON_ERROR)));
            self::assertSame('disabled',Server::get(\OCA\Mcp\Modules\Mail\Integration\MailCapabilities::class)->forUser($owner)['capabilities']['send']['status']);
        }finally{
            if($draftId!==null){$args=['draft_id'=>$draftId];$p=$this->data($this->alice->tool('mail_delete_draft',$args));$this->data($this->alice->tool('mail_delete_draft',$args+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]));}
            if($tagId!==null){$tag=$tagMap->getTagForUser($tagId,$owner);if(!str_starts_with($tag->getDisplayName(),$name))throw new \LogicException('Not this fixture tag');$tagMap->delete($tag);}
            MailActor::run($owner,'c3-cleanup',['fixture'=>$f]);
        }
    }
    private function releaseOwnSlot(string $operation,string $owner,int $account):void {
        // Disposable-fixture clock substitute: remove only this successful test's own slot, never another operation.
        $q=Server::get(\OCP\IDBConnection::class)->getQueryBuilder();$q->delete('mcp_mail_probe_slots')->where($q->expr()->eq('scope_key',$q->createNamedParameter(hash('sha256',"mutation\0".$owner."\0".$account))))->andWhere($q->expr()->eq('operation_id',$q->createNamedParameter($operation)))->executeStatement();
    }
    private function data(ToolReply $r):array{self::assertTrue($r->ok(),$r->describe());return $r->structured()!==[]?$r->structured():$r->json();}
}
