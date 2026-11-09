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
final class McpMailTagCreationTest extends IntegrationTestCase {
    private const TOOLS = ['mail_create_tag','mail_list_tags','mail_get_operation','mail_list_operations'];
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



    public function testNativeHttpCreationIsReviewedUserOwnedAndNeverReplayed():void {
        $uid=MailActor::ALICE;$map=Server::get('OCA\\Mail\\Db\\TagMapper');$safe=MailActor::run($uid,'c1-safety');$ids=[];$name='C5 Revisión '.bin2hex(random_bytes(5));$args=['name'=>$name,'color'=>'#AABBCC'];
        try{
            $p=$this->data($this->alice->tool('mail_create_tag',$args));self::assertTrue($p['requiresConfirmation']);self::assertSame('all_owned_accounts',$p['scope']);$c=$args+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];$r=$this->data($this->alice->tool('mail_create_tag',$c));self::assertSame('succeeded',$r['operation']['state'],json_encode($r));$id=$r['tag']['tag_id'];$ids[]=$id;$tag=$map->getTagForUser($id,$uid);self::assertSame($name,$tag->getDisplayName());self::assertSame('#aabbcc',$tag->getColor());self::assertFalse($tag->getIsDefaultTag());self::assertSame($id,$r['operation']['resource_refs']['tag_id']);self::assertArrayNotHasKey('imap_label',$r['tag']);
            $count=count($map->getAllTagsForUser($uid));self::assertTrue($this->data($this->alice->tool('mail_create_tag',$c))['operation']['replayed']);self::assertCount($count,$map->getAllTagsForUser($uid));$this->data($this->alice->tool('mail_get_operation',['operation_id'=>$p['operation_id']]));self::assertFalse($this->bob->tool('mail_get_operation',['operation_id'=>$p['operation_id']])->ok());self::assertFalse($this->alice->tool('mail_create_tag',$args)->ok());
            foreach(['Label1',str_repeat('x',64),'bad*']as$bad)self::assertFalse($this->alice->tool('mail_create_tag',['name'=>$bad,'color'=>'#123456'])->ok());
            ToolPermissionFixture::assign($uid,['mail_create_tag'=>'deny']);self::assertFalse($this->alice->tool('mail_get_operation',['operation_id'=>$p['operation_id']])->ok());self::assertFalse($this->alice->tool('mail_create_tag',$c)->ok());$this->data($this->alice->tool('mail_list_tags'));
            self::assertSame($safe,MailActor::run($uid,'c1-safety'));
        }finally{foreach($ids as$id){$t=$map->getTagForUser($id,$uid);if($t->getDisplayName()!==$name)throw new \LogicException('Not this fixture tag');$map->delete($t);}}
    }
    public function testNativeInsertAndJournalEvidenceRollbackOrCommitTogether():void {
        $uid=MailActor::ALICE;$native=Server::get(\OCP\IDBConnection::class);$journal=Server::get(\OCA\Mcp\Mcp\Operations\OperationJournal::class);$map=Server::get('OCA\\Mail\\Db\\TagMapper');$safe=MailActor::run($uid,'c1-safety');
        foreach(['rollback','before_commit','after_commit']as$mode){$name='C5 fault '.bin2hex(random_bytes(6));$args=['name'=>$name,'color'=>'#123456'];$p=$journal->plan('mail_create_tag',$uid,$args,['plan_state'=>'fixture']);$args+=['operation_id'=>$p['operation_id'],'plan_state'=>'fixture'];$id=null;
            $db=$this->createMock(\OCP\IDBConnection::class);$db->method('inTransaction')->willReturnCallback(fn():bool=>$native->inTransaction());$db->method('beginTransaction')->willReturnCallback(fn()=>$native->beginTransaction());$db->method('rollBack')->willReturnCallback(fn()=>$native->rollBack());$db->method('close')->willReturnCallback(fn()=>$native->close());$db->method('commit')->willReturnCallback(function()use($native,$mode):void{if($mode==='before_commit')throw new \RuntimeException('Commit unavailable');$native->commit();if($mode==='after_commit')throw new \RuntimeException('Commit acknowledgement lost');});
            try{
                try{$out=$journal->execute('mail_create_tag',$uid,$args,function()use($journal,$db,$map,$uid,$name,$mode,&$id):array{return $journal->atomicLocal($db,function()use($map,$uid,$name,$journal,$mode,&$id):array{$t=new \OCA\Mail\Db\Tag();$t->setUserId($uid);$t->setDisplayName($name);$t->setImapLabel(Server::get('OCA\\Mail\\IMAP\\ImapFlag')->create($name));$t->setColor('#123456');$t->setIsDefaultTag(false);$id=(int)$map->insert($t)->getId();$journal->checkpoint(['tag_id'=>$id]);if($mode==='rollback')throw new \RuntimeException('Fixture readback failure');return []; });});}catch(\OCA\Mcp\Mcp\Operations\BeforeEffectFailure){$out=['operation'=>$journal->get($uid,$p['operation_id'])];}
                self::assertSame($mode==='rollback'?'failed_before_effect':'uncertain',$out['operation']['state']);$exists=false;foreach($map->getAllTagsForUser($uid)as$t)if((int)$t->getId()===$id)$exists=true;self::assertSame($mode==='after_commit',$exists);self::assertSame($exists,isset($out['operation']['resource_refs']['tag_id']));self::assertTrue($journal->execute('mail_create_tag',$uid,$args,static fn():array=>throw new \LogicException('Never repeat native insert'))['operation']['replayed']);
            }finally{
                if($native->inTransaction())$native->rollBack();foreach($map->getAllTagsForUser($uid)as$t)if((int)$t->getId()===$id){if($t->getDisplayName()!==$name)throw new \LogicException('Not this fixture tag');$map->delete($t);}
                // Exact synthetic admission only. Product uncertainty is never cleared by this fixture cleanup.
                $q=$native->getQueryBuilder();$q->delete('mcp_operations')->where($q->expr()->eq('operation_id',$q->createNamedParameter($p['operation_id'])))->andWhere($q->expr()->eq('scope_key',$q->createNamedParameter($journal->scopeKey($uid))))->executeStatement();
            }
        }
        self::assertSame($safe,MailActor::run($uid,'c1-safety'));
    }
    private function data(ToolReply $r):array{self::assertTrue($r->ok(),$r->describe());return $r->structured()!==[]?$r->structured():$r->json();}
}
