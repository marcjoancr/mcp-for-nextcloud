<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Access\{MailAccessGuard,MailOperationAuthorization};
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\Operations\MailOperationReader;
use OCA\Mcp\Modules\Mail\MailToolCatalog;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\{FilesScope,FilesAccessGuard};
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Confirmation\{PlanState,WriteGate};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Db\OperationsMapper;
use OCA\Mcp\Migration\Version002700Date20261107000000;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Support\Dav\ScopeStub;
use OCA\Mcp\Tests\Unit\{SqliteDatabase,InMemoryConfig};
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class MailOperationScopeTest extends TestCase {
    private function fixture():array {
        $sql=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($sql->connection());
        $context=new ConnectionContext();$context->set(12);$clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $journal=new OperationJournal($store,new PlanState((new InMemoryConfig())->mock($this)),$context,$clock, new \OCA\Mcp\Tests\Support\OperationActors());
        $scope=new ScopeStub();$access=new MailAccessGuard($scope);$policy=$this->createMock(GrantPolicy::class);$policy->method('scopeItems')->willReturn(null);
        $ids=new class {public bool $deleted=false;public int $calls=0;public function identities(string $uid,string $kind,array $ids):array {$this->calls++;if($this->deleted)return [];return array_map(static fn(int $id):array=>['id'=>$id,'account_id'=>$kind==='account'?$id:3,'mailbox_id'=>match($kind){'message'=>$id===102?8:7,'mailbox'=>$id,default=>0}],$ids);}};
        $native=$this->createMock(MailServices::class);$native->method('boundedMetadata')->willReturn($ids);
        $native->method('accountService')->willReturn(new class{public function find(string $uid,int $id):object{return new class{public function getMailAccount():object{return new class{public function getArchiveMailboxId():int{return 8;}public function getTrashMailboxId():int{return 8;}};}};}});
        $native->method('guardedDelivery')->willReturn(new class{public function accessContext(string $uid,string $id):array{return ['accountId'=>3,'sourceTool'=>'mail_send'];}});
        $files=$this->createMock(FilesScope::class);$files->method('candidates')->willReturn(['91']);
        $root=$this->createMock(\OCP\Files\Folder::class);$root->method('isReadable')->willReturn(true);$roots=$this->createMock(\OCP\Files\IRootFolder::class);$roots->method('getUserFolder')->willReturn($root);
        $authorization=new MailOperationAuthorization($access,$native,$journal,$policy,$files,$this->createMock(FilesAccessGuard::class),$roots,new \OCA\Mcp\Modules\Mail\Integration\MailTagVisibility($native,$access),$this->createMock(\OCA\Mcp\Modules\Mail\Access\MailDeliveryOrigin::class));
        $reader=new MailOperationReader($journal,static fn()=>true,authorization:$authorization);
        $store->reserve(['scope_key'=>$journal->scopeKey('alice'),'actor_uid'=>'alice','operation_id'=>'prior','tool_name'=>'mail_write_account_settings','effect_digest'=>'prior','target_key'=>null,'state'=>'succeeded','resource_refs'=>['actor_incarnation'=>$journal->actor('alice'),'authorization'=>['v'=>1,'mail'=>[['account'=>3,'mailbox'=>0]],'global'=>false,'files'=>[],'tools'=>[]]],'created_at'=>1,'updated_at'=>1]);
        return [$journal,$authorization,$reader,$scope,$ids,$store];
    }
    public function testBeforeEffectFailureRetainsPrivateOriginalProvenanceForRestrictedStatus():void {
        [$journal,$auth,$reader,$scope]=$this->fixture();$plan=$journal->plan('mail_write_draft','alice',['account_id'=>3],['plan_state'=>'proof']);
        try{$journal->execute('mail_write_draft','alice',['account_id'=>3,'operation_id'=>$plan['operation_id'],'plan_state'=>'proof'],function()use($auth):array{$auth->capture('alice','mail_write_draft',['account_id'=>3]);throw new \OCA\Mcp\Mcp\Operations\BeforeEffectFailure('Stopped before write');});}catch(\OCA\Mcp\Mcp\Operations\BeforeEffectFailure){}
        $scope->restrict('mail',['account:3']);$result=$reader->get('alice',$plan['operation_id']);self::assertSame('failed_before_effect',$result['operation']['state']);self::assertArrayNotHasKey('authorization',$result['operation']['resource_refs']);
        $scope->restrict('mail',[]);$this->expectException(ToolFailure::class);$reader->get('alice',$plan['operation_id']);
    }
    public static function writes():array {
        $cases=[];
        foreach(MailToolCatalog::definitions() as $name=>$d){
            if(!WriteGate::isWrite($d))continue;
            $args=[];$p=$d['inputSchema']['properties'];
            foreach(['tag_id'=>7,'account_id'=>3,'draft_id'=>55,'message_id'=>101,'mailbox_id'=>7,'destination_mailbox_id'=>8,'target_account_id'=>4,'message_ids'=>[101,102],'delivery_id'=>'delivery','path'=>'/export.eml','target_operation_id'=>'prior','setup_operation_id'=>'prior'] as $k=>$v)if(isset($p[$k]))$args[$k]=$v;
            // Add-account's account_id is absent; optional unrelated operation references are not selected.
            $cases[$name]=[$name,$args];
        }
        return $cases;
    }
    #[DataProvider('writes')]
    public function testEveryWriteRetainsPrivateOriginalProvenanceAndRevokedStatusAndReplayAreDenied(string $tool,array $args):void {
        [$j,$auth,$reader,$scope,$ids]=$this->fixture();$plan=$j->plan($tool,'alice',$args,['plan_state'=>'proof']);$args+=['operation_id'=>$plan['operation_id'],'plan_state'=>'proof'];
        $result=$j->execute($tool,'alice',$args,function()use($auth,$tool,$args):array{$auth->capture('alice',$tool,$args);return ['effect'=>'done'];});
        self::assertSame('succeeded',$result['operation']['state'],$tool);
        self::assertArrayNotHasKey('authorization',$result['operation']['resource_refs']);self::assertArrayNotHasKey('source_tool',$result['operation']);
        $calls=$ids->calls;$ids->deleted=true;
        self::assertSame('succeeded',$reader->get('alice',$plan['operation_id'])['operation']['state']);self::assertSame($calls,$ids->calls,'Historical access must never refetch a deleted source');
        $scope->restrict('mail',[]);
        try{$reader->get('alice',$plan['operation_id']);self::fail('Revoked status exposed');}catch(ToolFailure){self::assertTrue(true);}
        $this->expectException(ToolFailure::class);
        $j->execute($tool,'alice',$args,static fn()=>throw new \LogicException('Effect must never repeat'),fn(array $r)=>$reader->assertAccess('alice',$r));
    }
    public function testMissingHistoricalProvenanceFailsClosedUnderRestriction():void {
        [$j,$auth,$reader,$scope,$ids,$store]=$this->fixture();
        $store->reserve(['scope_key'=>$j->scopeKey('alice'),'actor_uid'=>'alice','operation_id'=>'old','tool_name'=>'mail_set_message_flag','effect_digest'=>'old','target_key'=>null,'state'=>'succeeded','resource_refs'=>['account_id'=>3],'created_at'=>1,'updated_at'=>1]);
        $scope->restrict('mail',['account:3']);$this->expectException(ToolFailure::class);$reader->get('alice','old');
    }
}
