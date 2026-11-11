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
final class MailSignatureModeProjectionTest extends TestCase {
    public static function cases():array {$out=[];foreach(['preview','journal_error','recovery','replay']as$phase)foreach(['scope','grant','selection','actor']as$revoke)$out[$phase.' '.$revoke]=[$phase,$revoke];return $out;}
    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testFinalProjectionCannotReuseTheEnclosingMaterializedPolicy(string $phase,string $revoke):void {
        $uid='alice';$name='mail_write_signature_mode';$fx=new ScopeFixture($this);$activation=new ScopeActivation(['mail'=>true]);$services=$fx->services(activation:$activation);
        foreach(['write_signature_mode','read_operations']as$op)$services->grants->setGrant($uid,'mail',$op,true);$services->grants->setEligible($uid,true);$services->grants->setConnected($uid,true);$services->service->setGlobalEnabled(true);
        $writer=$fx->commands(resources:$fx->enumerator((new FakeMailApp())->withAccount(3,$uid,'A','a@example.test')),activation:$activation);$writer->setResourceScope($fx->revision(),'root',$uid,'mail',['account:3']);
        $grants=new \OCA\Mcp\Db\ToolGrantsMapper($fx->db);$grant=function(string $value)use($fx,$grants,$uid,$name):void {$fx->db->beginTransaction();$grants->set('user',$uid,$name,$value);$fx->db->commit();};$grant('allow');
        $policy=$fx->policy($services,$grants);$clock=$this->createStub(\OCP\AppFramework\Utility\ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $fx->database->dbal->executeStatement('CREATE TABLE mcp_oauth_tools (user_id TEXT, connection_id INTEGER, tool_names TEXT, tool_variants TEXT, revision INTEGER)');
        $selections=new ConnectionToolsStore($fx->db,$clock,$this->createMock(TokenOwnerGate::class),new AuthUsersMapper($fx->db),$fx->state,new OAuthClientsMapper($fx->db));$selections->save($uid,0,[$name,'mail_get_operation'],'0');$context=new ConnectionContext();
        $db=new \OCA\Mcp\Tests\Unit\SqliteDatabase($this,[new \OCA\Mcp\Migration\Version002700Date20261107000000()]);$store=new \OCA\Mcp\Db\OperationsMapper($db->connection());$journal=new OperationJournal($store,new \OCA\Mcp\Mcp\Confirmation\PlanState($fx->config->mock($this)),$context,$clock,$actors=new \OCA\Mcp\Tests\Support\OperationActors());
        $apps=$this->createMock(\OCP\App\IAppManager::class);$apps->method('isEnabledForUser')->willReturn(true);$apps->method('getAppVersion')->willReturn('99.0.0');$apps->method('getAppPath')->willReturn('/native-mail');$guests=$this->createMock(\OCA\Mcp\Access\Guests\GuestRestrictions::class);$guests->method('allowsApp')->willReturn(true);
        $fresh=new MailFreshAccess($policy,$apps,$fx->userManager(),$guests,$context,$selections,$journal);$access=new MailAccessGuard($policy);
        $revoked=false;$change=function()use(&$revoked,$revoke,$writer,$fx,$uid,$grant,$selections,$actors):void {$revoked=true;match($revoke){'scope'=>$writer->setResourceScope($fx->revision(),'root',$uid,'mail',[]),'grant'=>$grant('deny'),'selection'=>$selections->save($uid,0,[],'1'),'actor'=>$actors->generation='replacement'};};
        $refs=['v'=>1,'mail'=>[['account'=>3,'mailbox'=>0]],'global'=>false,'files'=>[],'tools'=>[]];$effect=function()use($journal,$uid,$refs,$change):array {$journal->recordAuthorization($uid,$refs);$change();throw new \RuntimeException('Synthetic post-admission failure.');};
        $preview=function()use($change):array {$change();return ['account_id'=>3,'filters'=>[['name'=>'PRIVATE_PLAN_CANARY']],'plan_state'=>'proof'];};
        $tool=new class($preview,$effect)implements \OCA\Mcp\Mcp\Tools\WriteTool {
            public function __construct(private \Closure $preview,private \Closure $effect){}
            public static function definition():\OCA\Mcp\Mcp\Tools\ToolDefinition{return \OCA\Mcp\Modules\Mail\Tools\WriteSignatureMode::definition();}
            public function preview(array $arguments,\OCA\Mcp\Mcp\Tools\ToolContext $context):array{return ($this->preview)();}
            public function execute(array $arguments,\OCA\Mcp\Mcp\Tools\ToolContext $context):array{return ($this->effect)();}
        };
        $reader=new MailOperationReader($journal,fn()=> $policy->toolGranted($uid,$name,'mail','write_signature_mode'),null,null,null,null,null,$fresh);
        $module=new MailModule(array_fill_keys(array_keys(MailToolCatalog::definitions()),fn()=>$tool),$access,$journal,$reader,null,$fresh);
        $args=['account_id'=>3,'plan_state'=>'proof'];$plan=$journal->plan($name,$uid,$args,['plan_state'=>'proof']);$args['operation_id']=$plan['operation_id'];
        if(in_array($phase,['recovery','replay'],true)){$journal->execute($name,$uid,$args,function()use($journal,$uid,$refs):array{$journal->recordAuthorization($uid,$refs);$journal->checkpoint(['account_id'=>3,'alias_id'=>7,'signature_mode'=>1]);return [];});$store->compact(1001);self::assertSame(['account_id'=>3,'alias_id'=>7,'signature_mode'=>1],$journal->get($uid,$args['operation_id'])['resource_refs']);}
        $policy->within($uid,function()use($policy,$access,$uid,$name,$module,$reader,$args,$phase,$change,$journal,$store,&$revoked):void {
            self::assertTrue($policy->toolGranted($uid,$name,'mail','write_signature_mode'));self::assertTrue($access->accountAllowed($uid,3));$denied=false;$projected=null;
            try{$projected=match($phase){'preview'=>$module->preview($name,$args,$uid),'journal_error'=>$module->call($name,$args,$uid),'replay'=>(function()use($change,$module,$name,$args,$uid){$change();return $module->call($name,$args,$uid);})(),'recovery'=>(function()use($change,$reader,$uid,$args){$change();return $reader->get($uid,$args['operation_id']);})()};}catch(ToolFailure){$denied=true;}
            self::assertTrue($policy->toolGranted($uid,$name,'mail','write_signature_mode'),'The old policy snapshot is still materialized');self::assertTrue($access->accountAllowed($uid,3),'The old scope snapshot is still materialized');
            self::assertTrue($revoked,'The actual revocation must have occurred inside the operation');self::assertTrue($denied,'Final projection exposed a plan or journal-generated receipt after actual revocation');self::assertNull($projected);
            if($phase==='journal_error')self::assertSame('uncertain',$store->get($journal->scopeKey($uid),$args['operation_id'])['state'],'Private durable failure facts must remain');
        });
    }
}
