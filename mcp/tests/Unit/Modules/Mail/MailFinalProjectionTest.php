<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCA\Mcp\Modules\Mail\Access\{MailAccessGuard,MailFreshAccess};
use OCA\Mcp\Modules\Mail\{MailModule,MailToolCatalog};
use OCA\Mcp\Modules\Mail\Operations\MailOperationReader;
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\OAuth\{ConnectionContext,ConnectionToolsStore,TokenOwnerGate};
use OCA\Mcp\Db\{AuthUsersMapper,OAuthClientsMapper};
use PHPUnit\Framework\TestCase;
final class MailFinalProjectionTest extends TestCase {
    public static function cases():array {
        $out=[];
        foreach(['SendDraft','ExportMessage','IndexMessages','ImportDraft','SetMessageFlag','WriteDraft']as$family)
            foreach(['preview','journal_error','success','plan_changed','local_unknown','delegated','recovery','replay','enrichment','list']as$phase)
                foreach(['scope','files_scope','grant','selection','actor','connection','none']as$revoke){if($phase==='delegated'&&$family!=='SetMessageFlag')continue;if($revoke==='connection'&&in_array($phase,['recovery','replay','list'],true))continue;$out[$family.' '.$phase.' '.$revoke]=[$family,$phase,$revoke];}
        foreach(['ReadMessage','ReadDelivery','SearchIndexedBody','ListAccounts']as$family)
            foreach(['scope','files_scope','grant','selection','actor','connection','none']as$revoke)$out[$family.' read '.$revoke]=[$family,'read',$revoke];
        foreach(['recovery','enrichment','list']as$phase)foreach(['status_grant','status_selection','dependency_grant','dependency_selection']as$revoke)$out['SendDraft '.$phase.' '.$revoke]=['SendDraft',$phase,$revoke];
        foreach(['SendDraft'=>'delivery_enrichment','SnoozeMessage'=>'snooze_enrichment','WriteFilters'=>'sieve_enrichment']as$family=>$phase)
            foreach(['scope','files_scope','grant','selection','actor','connection','status_grant','status_selection','dependency_grant','dependency_selection','none']as$revoke)$out[$family.' '.$phase.' '.$revoke]=[$family,$phase,$revoke];
        foreach(['dependency_grant','dependency_selection']as$revoke){$out['WriteDraft preview '.$revoke]=['WriteDraft','preview',$revoke];$out['ReadDelivery read '.$revoke]=['ReadDelivery','read',$revoke];}
        return $out;
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testFinalProjectionCannotReuseTheEnclosingMaterializedPolicy(string $family,string $phase,string $revoke):void {
        $class='OCA\\Mcp\\Modules\\Mail\\Tools\\'.$family;$definition=$class::definition();$name=$definition->name;$operation=$definition->operation;$uid='alice';$fx=new ScopeFixture($this);$activation=new ScopeActivation(['mail'=>true,'files'=>true]);$services=$fx->services(activation:$activation);
        foreach([$operation,'read_operations','read_message']as$op)$services->grants->setGrant($uid,'mail',$op,true);$services->grants->setGrant($uid,'files','create',true);$services->grants->setEligible($uid,true);$services->grants->setConnected($uid,true);$services->service->setGlobalEnabled(true);
        $writer=$fx->commands(resources:$fx->enumerator((new FakeMailApp())->withAccount(3,$uid,'A','a@example.test')),activation:$activation);$writer->setResourceScope($fx->revision(),'root',$uid,'mail',['account:3']);
        $grants=new \OCA\Mcp\Db\ToolGrantsMapper($fx->db);$grant=function(string $value,?string $tool=null)use($fx,$grants,$uid,$name):void {$fx->db->beginTransaction();$grants->set('user',$uid,$tool??$name,$value);$fx->db->commit();};$grant('allow');foreach(['mail_read_message','mail_get_operation','mail_list_operations']as$dependency)$grant('allow',$dependency);
        $policy=$fx->policy($services,$grants);$clock=$this->createStub(\OCP\AppFramework\Utility\ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $fx->database->dbal->executeStatement('CREATE TABLE mcp_oauth_tools (user_id TEXT, connection_id INTEGER, tool_names TEXT, tool_variants TEXT, revision INTEGER)');
        $selections=new ConnectionToolsStore($fx->db,$clock,$this->createMock(TokenOwnerGate::class),new AuthUsersMapper($fx->db),$fx->state,new OAuthClientsMapper($fx->db));$selected=array_values(array_unique([$name,'mail_get_operation','mail_list_operations','mail_read_message']));$selections->save($uid,0,$selected,'0');$context=new ConnectionContext();
        $db=new \OCA\Mcp\Tests\Unit\SqliteDatabase($this,[new \OCA\Mcp\Migration\Version002700Date20261107000000()]);$store=new \OCA\Mcp\Db\OperationsMapper($db->connection());$journal=new OperationJournal($store,new \OCA\Mcp\Mcp\Confirmation\PlanState($fx->config->mock($this)),$context,$clock,$actors=new \OCA\Mcp\Tests\Support\OperationActors());
        $apps=$this->createMock(\OCP\App\IAppManager::class);$apps->method('isEnabledForUser')->willReturn(true);$apps->method('getAppVersion')->willReturn('99.0.0');$apps->method('getAppPath')->willReturn('/native-mail');$guests=$this->createMock(\OCA\Mcp\Access\Guests\GuestRestrictions::class);$guests->method('allowsApp')->willReturn(true);
        $fresh=new MailFreshAccess($policy,$apps,$fx->userManager(),$guests,$context,$selections,$journal);$access=new MailAccessGuard($policy);
        $revoked=false;$effects=0;
        $change=function()use(&$revoked,$revoke,$writer,$fx,$uid,$grant,$selections,$actors,$context,$selected,$phase):void {
            $revoked=$revoke!=='none';$status=$phase==='list'?'mail_list_operations':'mail_get_operation';
            match($revoke){
                'scope'=>$writer->setResourceScope($fx->revision(),'root',$uid,'mail',[]),
                'files_scope'=>$writer->setResourceScope($fx->revision(),'root',$uid,'files',[]),
                'grant'=>$grant('deny'),'selection'=>$selections->save($uid,0,[],$selections->get($uid,0)['revision']),
                'actor'=>$actors->generation='replacement','connection'=>$context->set(9),
                'status_grant'=>$grant('deny',$status),
                'status_selection'=>$selections->save($uid,0,array_values(array_diff($selections->get($uid,0)['tools'],[$status])),$selections->get($uid,0)['revision']),
                'dependency_grant'=>$grant('deny','mail_read_message'),
                'dependency_selection'=>$selections->save($uid,0,array_values(array_diff($selections->get($uid,0)['tools'],['mail_read_message'])),$selections->get($uid,0)['revision']),
                'none'=>null,
            };
        };
        $refs=['v'=>1,'mail'=>[['account'=>3,'mailbox'=>0]],'global'=>false,'files'=>[],'tools'=>['mail_read_message']];
        $effect=function()use($journal,$uid,$refs,$change,$phase,$store,&$effects):array {
            ++$effects;
            if($phase==='read'){$change();return \OCA\Mcp\Mcp\Tools\Results\ToolResult::json(['private'=>'PRIVATE_RESULT_CANARY']);}
            $journal->recordAuthorization($uid,$refs);
            if($phase==='delegated'){
                $descriptor=['account_id'=>3,'message_id'=>17,'mailbox_id'=>7,'mailbox'=>'INBOX','generation'=>'42','uid'=>'9','profile_revision'=>'fixture','cache_revision'=>'fixture','flag'=>'seen','value'=>true];
                $d=$journal->delegateNative($uid,$descriptor);$prior='prepared';
                foreach(['wire_may_start','remote_verified','cache_pending','completed']as$next){
                    self::assertTrue($store->checkpointNative($d['scope'],$d['id'],$d['token'],$prior,['phase'=>$next]+($next==='remote_verified'?['remote'=>['outcome'=>'verified']]:[])));$prior=$next;
                }
            }
            $change();
            if($phase==='plan_changed')throw new \OCA\Mcp\Mcp\Confirmation\PlanChanged(['private'=>'PRIVATE_PLAN_CANARY','plan_state'=>'new-proof']);
            if($phase==='local_unknown')throw new \OCA\Mcp\Mcp\Operations\LocalCommitUnknown('Synthetic lost commit acknowledgement.');
            if(in_array($phase,['journal_error','delegated'],true))throw new \RuntimeException('Synthetic post-admission failure.');
            return \OCA\Mcp\Mcp\Tools\Results\ToolResult::json(['private'=>'PRIVATE_RESULT_CANARY']);
        };
        $preview=function()use($change):array {$change();return ['account_id'=>3,'private'=>'PRIVATE_PLAN_CANARY','plan_state'=>'proof'];};
        $tool=new class($preview,$effect,$definition)implements \OCA\Mcp\Mcp\Tools\WriteTool {
            private static \OCA\Mcp\Mcp\Tools\ToolDefinition $toolDefinition;
            public function __construct(private \Closure $preview,private \Closure $effect,\OCA\Mcp\Mcp\Tools\ToolDefinition $definition){self::$toolDefinition=$definition;}
            public static function definition():\OCA\Mcp\Mcp\Tools\ToolDefinition{return self::$toolDefinition;}
            public function preview(array $arguments,\OCA\Mcp\Mcp\Tools\ToolContext $context):array{return ($this->preview)();}
            public function execute(array $arguments,\OCA\Mcp\Mcp\Tools\ToolContext $context):array{return ($this->effect)();}
        };
        $reader=new MailOperationReader($journal,fn()=> $policy->toolGranted($uid,$name,'mail',$operation),$phase==='enrichment'?function()use($change):array{$change();return ['private'=>'PRIVATE_ENRICHMENT_CANARY'];}:null,$phase==='delivery_enrichment'?function()use($change):array{$change();return ['private'=>'PRIVATE_DELIVERY_CANARY'];}:null,$phase==='snooze_enrichment'?function()use($change):array{$change();return ['private'=>'PRIVATE_SNOOZE_CANARY'];}:null,$phase==='sieve_enrichment'?function()use($change):array{$change();return ['private'=>'PRIVATE_SIEVE_CANARY'];}:null,$fresh);
        $authorization=null;$resolved=0;
        if(($phase==='preview'&&in_array($family,['SendDraft','WriteDraft'],true))||($phase==='read'&&in_array($family,['ReadMessage','ReadDelivery'],true))){
            $native=$this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailServices::class);
            $native->method('boundedMetadata')->willReturn(new class($resolved) {
                public function __construct(private int &$resolved){}
                public function identities(string $uid,string $kind,array $ids):array {++$this->resolved;return array_map(static fn(int $id):array=>['id'=>$id,'account_id'=>3,'mailbox_id'=>$kind==='message'?7:0],$ids);}
            });
            $origin=$this->createMock(\OCA\Mcp\Modules\Mail\Access\MailDeliveryOrigin::class);
            if($family==='ReadDelivery'){
                $original=$journal->plan('mail_send',$uid,[],['plan_state'=>'original']);
                $journal->execute('mail_send',$uid,['operation_id'=>$original['operation_id'],'plan_state'=>'original'],function()use($journal,$uid,$refs):array{$journal->recordAuthorization($uid,$refs);return [];});
                $origin->method('assertCurrent')->willReturn($original['operation_id']);
                $native->method('guardedDelivery')->willReturn(new class {public function accessContext(string $uid,string $id):array {return ['accountId'=>3,'sourceTool'=>'mail_send'];}});
                $grant('allow','mail_send');$services->grants->setGrant($uid,'mail','send',true);$selected[]='mail_send';$selections->save($uid,0,$selected,'1');
            }
            $authorization=new \OCA\Mcp\Modules\Mail\Access\MailOperationAuthorization($access,$native,$journal,$policy,$this->createMock(\OCA\Mcp\Access\ResourceScope\FilesScope::class),$this->createMock(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class),$this->createMock(\OCP\Files\IRootFolder::class),new \OCA\Mcp\Modules\Mail\Integration\MailTagVisibility($native,$access),$origin);
        }
        $module=new MailModule(array_fill_keys(array_keys(MailToolCatalog::definitions()),fn()=>$tool),$access,$journal,$reader,$authorization,$fresh);
        $args=['account_id'=>3,'plan_state'=>'proof'];$plan=$journal->plan($name,$uid,$args,['plan_state'=>'proof']);$args['operation_id']=$plan['operation_id'];
        if($authorization!==null){unset($args['account_id']);$args+=match($family){'SendDraft'=>['draft_id'=>55],'WriteDraft'=>['account_id'=>3,'reply_to_message_id'=>101],'ReadMessage'=>['message_id'=>101],'ReadDelivery'=>['delivery_id'=>str_repeat('a',64)]};}
        if(in_array($phase,['recovery','replay','enrichment','delivery_enrichment','snooze_enrichment','sieve_enrichment','list'],true)){$journal->execute($name,$uid,$args,function()use($journal,$uid,$refs,$phase):array{$journal->recordAuthorization($uid,$refs);$journal->checkpoint(['account_id'=>3]);if($phase==='enrichment')$journal->awaitingUser(2000);return [];});if($phase!=='enrichment'){$store->compact(1001);self::assertSame(['account_id'=>3],$journal->get($uid,$args['operation_id'])['resource_refs']);}}
        $policy->within($uid,function()use($policy,$access,$uid,$name,$module,$reader,$args,$phase,$change,$journal,$store,&$revoked,$operation,$revoke,&$effects):void {
            self::assertTrue($policy->toolGranted($uid,$name,'mail',$operation));self::assertTrue($access->accountAllowed($uid,3));$denied=false;$projected=null;
            try{$projected=match($phase){'preview'=>$module->preview($name,$args,$uid),'journal_error','success','plan_changed','local_unknown','delegated','read'=>$module->call($name,$args,$uid),'replay'=>(function()use($change,$module,$name,$args,$uid){$change();return $module->call($name,$args,$uid);})(),'enrichment','delivery_enrichment','snooze_enrichment','sieve_enrichment'=>$reader->get($uid,$args['operation_id']),'list'=>(function()use($change,$reader,$uid){$change();return $reader->list($uid,20,0);})(),'recovery'=>(function()use($change,$reader,$uid,$args){$change();return $reader->get($uid,$args['operation_id']);})()};}catch(ToolFailure){$denied=true;}catch(\OCA\Mcp\Mcp\Confirmation\PlanChanged $changed){$projected=$changed->plan;}
            self::assertTrue($policy->toolGranted($uid,$name,'mail',$operation),'The old policy snapshot is still materialized');self::assertTrue($access->accountAllowed($uid,3),'The old scope snapshot is still materialized');
            self::assertSame($revoke!=='none',$revoked,'The actual revocation must have occurred inside the operation');
            $rowFiltered=$phase==='list'&&in_array($revoke,['dependency_grant','dependency_selection','grant','actor'],true);
            if($rowFiltered){self::assertFalse($denied);self::assertSame([],$projected['operations']);}
            else {self::assertSame($revoke!=='none',$denied,'Final projection exposed private output after revocation, or denied unchanged authority');if($denied)self::assertNull($projected);else self::assertIsArray($projected);}
            if(in_array($phase,['recovery','replay','enrichment','delivery_enrichment','snooze_enrichment','sieve_enrichment','list'],true))self::assertSame(0,$effects,'Status/replay must not execute the effect');
            elseif($phase!=='preview')self::assertSame(1,$effects,'Exactly one original invocation');
            if($phase==='journal_error')self::assertSame('uncertain',$store->get(hash('sha256',$uid."\0" . 0),$args['operation_id'])['state'],'Private durable failure facts must remain');
        });
        if($authorization!==null)self::assertSame($family==='ReadDelivery'?0:($family==='WriteDraft'?2:1),$resolved,'Reads/previews pin owned identities; direct historical delivery never refetches deleted sources');
    }
}
