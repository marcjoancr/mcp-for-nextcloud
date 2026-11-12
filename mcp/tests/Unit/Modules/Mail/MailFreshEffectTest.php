<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCA\Mcp\Modules\Mail\Access\MailFreshAccess;
use OCA\Mcp\Modules\Mail\Integration\{NativeDraftWrites,NativeDraftLinks,MailServices};
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\OAuth\{ConnectionContext,ConnectionToolsStore,TokenOwnerGate};
use OCA\Mcp\Db\{AuthUsersMapper,OAuthClientsMapper};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class MailFreshEffectTest extends TestCase {
    public static function revocations():array {return ['scope'=>['scope'],'tool'=>['tool'],'connection selection'=>['connection'],'service'=>['service'],'Files source'=>['files'],'setup completion owner'=>['setup'],'cancel scope'=>['cancel_scope'],'cancel tool'=>['cancel_tool'],'cancel actor'=>['cancel_actor']];}
    #[DataProvider('revocations')]
    public function testRevocationAtNativeOwnerAdmissionStopsEffectInsideAnAlreadyOpenHttpSnapshot(string $revoke):void {
        $cancel=str_starts_with($revoke,'cancel_');$revoke=$cancel?substr($revoke,7):$revoke;$tool=$cancel?'mail_cancel_delivery':'mail_write_draft';
        $fx=new ScopeFixture($this);$activation=new ScopeActivation(['mail'=>true,'files'=>true]);$services=$fx->services(activation:$activation);
        $services->grants->setGrant('alice','mail',$cancel?'cancel_delivery':'write_drafts',true);$services->grants->setEligible('alice',true);$services->grants->setConnected('alice',true);$services->service->setGlobalEnabled(true);
        $writer=$fx->commands(resources:$fx->enumerator((new FakeMailApp())->withAccount(3,'alice','A','a@example.test')),activation:$activation);$writer->setResourceScope($fx->revision(),'root','alice','mail',['account:3']);
        $toolStore=new \OCA\Mcp\Db\ToolGrantsMapper($fx->db);$grant=function(string $decision)use($toolStore,$fx,$tool):void {$fx->db->beginTransaction();$toolStore->set('user','alice',$tool,$decision);$fx->db->commit();};$grant('allow');
        $policy=$fx->policy($services,$toolStore);$clock=$this->createStub(\OCP\AppFramework\Utility\ITimeFactory::class);
        $fx->database->dbal->executeStatement('CREATE TABLE mcp_oauth_tools (user_id TEXT, connection_id INTEGER, tool_names TEXT, tool_variants TEXT, revision INTEGER)');
        $selection=new ConnectionToolsStore($fx->db,$clock,$this->createMock(TokenOwnerGate::class),new AuthUsersMapper($fx->db),$fx->state,new OAuthClientsMapper($fx->db));
        $selection->save('alice',0,[$tool],'0');$context=new ConnectionContext();
        $operationDb=new \OCA\Mcp\Tests\Unit\SqliteDatabase($this,[new \OCA\Mcp\Migration\Version002700Date20261107000000()]);
        $journal=new OperationJournal(new \OCA\Mcp\Db\OperationsMapper($operationDb->connection()),new \OCA\Mcp\Mcp\Confirmation\PlanState($fx->config->mock($this)),$context,$clock, $actors=new \OCA\Mcp\Tests\Support\OperationActors());
        $apps=$this->createMock(\OCP\App\IAppManager::class);$apps->method('isEnabledForUser')->willReturn(true);$apps->method('getAppVersion')->willReturn('99.0.0');$apps->method('getAppPath')->willReturn('/native-mail');
        $guests=$this->createMock(\OCA\Mcp\Access\Guests\GuestRestrictions::class);$guests->method('allowsApp')->willReturn(true);
        self::assertSame([$tool],$selection->get('alice',0)['tools']);
        self::assertTrue($policy->toolGranted('alice',$tool,'mail',$cancel?'cancel_delivery':'write_drafts'));
        self::assertTrue((new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability($policy,$apps,$fx->userManager(),$guests))->allowed(\OCA\Mcp\Modules\Mail\MailToolCatalog::definitions()[$tool],'alice'));
        $access=new MailFreshAccess($policy,$apps,$fx->userManager(),$guests,$context,$selection,$journal);
        $change=function()use($revoke,$writer,$fx,$services,$selection,$grant,$actors):void {match($revoke){'actor'=>$actors->generation='two','scope','setup'=>$writer->setResourceScope($fx->revision(),'root','alice','mail',[]),'tool'=>$grant('deny'),'connection'=>$selection->save('alice',0,[],'1'),'service'=>$services->service->setGlobalEnabled(false),'files'=>$writer->setResourceScope($fx->revision(),'root','alice','files',[])};};
        $coordinator=new class($change){public function __construct(private \Closure $change){}public function runOwner(string $uid,\Closure $work):array {($this->change)();return $work(new \stdClass());}};
        $native=$this->createMock(MailServices::class);$native->method('draftsService')->willReturn(new class($coordinator){public function __construct(private object $coordinator){}public function draftCoordinator():object{return $this->coordinator;}});
        $links=$this->createMock(NativeDraftLinks::class);$links->method('available')->willReturn(true);$writes=new NativeDraftWrites($native,$links,$access);$effects=0;
        $effect=fn():array=>$writes->run('alice',function()use(&$effects):array{$effects++;return [];});
        if($cancel){
            $nativeCancel=new class($change){public int $effects=0;public function __construct(private \Closure $change){}public function accessContext(string $uid,string $id):array{return ['accountId'=>3];}public function cancel(string $uid,string $id,string $revision,?object $fence=null):array{($this->change)();$fence?->authorize($uid,['accountId'=>3]);$this->effects++;return [];}};
            $native->method('isEnabledFor')->willReturn(true);$native->method('guardedDelivery')->willReturn($nativeCancel);
            $boundary=$this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class);$boundary->method('runtimeMatches')->willReturn(true);$boundary->method('deliveryVerified')->willReturn(true);
            $db=$this->createMock(\OCP\IDBConnection::class);$db->method('getDatabaseProvider')->willReturn(\OCP\IDBConnection::PLATFORM_POSTGRES);
            $scope=new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub());
            $gateway=new \OCA\Mcp\Modules\Mail\Integration\MailDeliveryGateway($native,$boundary,$fx->config->mock($this),new \OCA\Mcp\Modules\Mail\Integration\MailGateway((new FakeMailApp())->withAccount(3,'alice','A','a@example.test'),$scope),$scope,$this->createMock(\OCA\Mcp\Modules\Mail\Access\MailDeliveryOrigin::class),$db,$access);
            $effect=fn():array=>$gateway->cancel('alice',str_repeat('a',64),'revision');
        }
        if($revoke==='setup'){
            $evidence=$this->createMock(\OCA\Mcp\Modules\Mail\Integration\NativeSetupEvidence::class);
            $evidence->method('withCompletion')->willReturnCallback(function(string $uid,\Closure $work)use($change):void{$change();$work();});
            $workflows=$this->createMock(\OCA\Mcp\Db\MailSetupWorkflowsMapper::class);$workflows->expects(self::never())->method('atomic');
            $setup=new class($this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailSettingsGateway::class),$workflows,$journal,new \OCA\Mcp\Mcp\Confirmation\PlanState($fx->config->mock($this)),$clock,$this->createMock(\OCA\Mcp\Modules\Mail\Integration\NativeAccountSetupLinks::class),static fn():bool=>true,$evidence,$access) extends \OCA\Mcp\Modules\Mail\Accounts\MailAccountSetup {
                public function previewCompletion(array $args,string $uid):array{return ['plan_state'=>'proof','source_tool'=>'mail_configure_connection','credential_write'=>['revision'=>1]];}
            };
            $effect=fn():array=>$setup->complete(['plan_state'=>'proof','setup_operation_id'=>str_repeat('a',32),'account_id'=>3],'alice');
        }
        $plan=$journal->plan($tool,'alice',['account_id'=>3],['plan_state'=>'proof']);$admitted=false;$denied=false;$failure=null;
        try{$policy->within('alice',function()use($access,$effect,&$effects,$journal,$plan,&$admitted,&$denied,&$failure,$policy,$tool,$cancel):void {
            self::assertTrue($policy->toolGranted('alice',$tool,'mail',$cancel?'cancel_delivery':'write_drafts'));
            self::assertTrue((new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard($policy))->accountAllowed('alice',3));
            $journal->execute($tool,'alice',['account_id'=>3,'operation_id'=>$plan['operation_id'],'plan_state'=>'proof'],function()use($access,$effect,&$effects,$journal,&$admitted,&$denied,&$failure):array {
                try {
                    $journal->recordAuthorization('alice',['v'=>1,'mail'=>[['account'=>3,'mailbox'=>0]],'global'=>false,'files'=>[['1']],'tools'=>[]]);
                    $access->assertActive('alice');$admitted=true;
                    try{$effect();}catch(ToolFailure){$denied=true;}
                }catch(\Throwable $error){$failure=$error;}
                return [];
            });
        });
        }catch(\OCA\Mcp\Mcp\Operations\ActorRetired){if(!$cancel||$revoke!=='actor')throw new \RuntimeException('Unexpected retirement');}
        if($failure!==null)throw $failure;
        self::assertTrue($admitted,'Initial allowed fence must really run');self::assertTrue($denied,'Intervening withdrawal must refuse the second fence');
        self::assertSame(0,$effects);if($cancel)self::assertSame(0,$nativeCancel->effects);
    }
}
