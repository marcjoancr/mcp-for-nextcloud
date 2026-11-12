<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;
use OCA\Mcp\Access\ResourceScope\{ScopeActivation,FilesScope,FilesAccessGuard};
use OCA\Mcp\Modules\Mail\Access\{MailAccessGuard,MailFreshAccess,MailOperationAuthorization,MailDeliveryOrigin};
use OCA\Mcp\Modules\Mail\Integration\{MailGateway,MailMessageGateway,MailContentReader,MailContentFeatures,MailNativeBoundary,MailTagVisibility};
use OCA\Mcp\Modules\Mail\{MailModule,MailToolCatalog};
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\OAuth\{ConnectionContext,ConnectionToolsStore,TokenOwnerGate};
use OCA\Mcp\Db\{AuthUsersMapper,OAuthClientsMapper,ToolGrantsMapper};
use PHPUnit\Framework\TestCase;
/** Actual module + folder-filtering handlers with real policy writer/snapshots; native cache is deterministic. */
final class MailFolderReadProjectionTest extends TestCase {
    public static function cases():array {
        $out=[];
        foreach(['list'=>['mail_list_mailboxes',['account_id'=>3]],'search account folder'=>['mail_search_messages',['account_id'=>3,'mailbox_id'=>7]],'search folder'=>['mail_search_messages',['mailbox_id'=>7]],'search account'=>['mail_search_messages',['account_id'=>3]],'search all'=>['mail_search_messages',[]],'drafts'=>['mail_search_drafts',['account_id'=>3]]]as$case=>[$tool,$args])
            foreach(['none','scope','grant','selection']as$change)$out[$case.' '.$change]=[$tool,$args,['mailbox:7'],$change,true];
        foreach(['hidden account'=>['mail_list_mailboxes',['account_id'=>4]],'foreign account'=>['mail_list_mailboxes',['account_id'=>5]],'hidden folder'=>['mail_search_messages',['account_id'=>3,'mailbox_id'=>8]],'foreign folder'=>['mail_search_messages',['account_id'=>5,'mailbox_id'=>9]],'wrong account'=>['mail_search_messages',['account_id'=>4,'mailbox_id'=>7]],'foreign drafts'=>['mail_search_drafts',['account_id'=>5]],'account settings'=>['mail_read_account_settings',['account_id'=>3]],'signature'=>['mail_write_signature',['account_id'=>3]],'composition'=>['mail_write_draft',['account_id'=>3]],'send'=>['mail_send',['draft_id'=>55]]]as$case=>[$tool,$args])$out[$case]=[$tool,$args,['mailbox:7'],'none',false];
        $out['configured Drafts folder not granted']=['mail_search_drafts',['account_id'=>3],['mailbox:8'],'none',false];
        return $out;
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testAccountFiltersPreserveFolderOnlyReadsAndFinalAuthority(string $name,array $args,array $scope,string $change,bool $initiallyAllowed):void {
        $fx=new ScopeFixture($this);$activation=new ScopeActivation(['mail'=>true,'files'=>true]);$services=$fx->services(activation:$activation);$definition=MailToolCatalog::definitions()[$name];
        $services->grants->setGrant('alice','mail',$definition['operation'],true);$services->grants->setEligible('alice',true);$services->grants->setConnected('alice',true);$services->service->setGlobalEnabled(true);
        $native=(new FolderProjectionNative())->withAccount(3,'alice','Visible','a@example.test')->withAccount(4,'alice','Hidden account','h@example.test')->withAccount(5,'bob','Foreign','b@example.test')->withMailbox(3,['id'=>7,'name'=>'Selected Drafts'])->withMailbox(3,['id'=>8,'name'=>'HIDDEN_FOLDER'])->withMailbox(5,['id'=>9,'name'=>'FOREIGN_FOLDER']);
        $writer=$fx->commands(resources:$fx->enumerator($native),activation:$activation);$writer->setResourceScope($fx->revision(),'root','alice','mail',$scope);
        $grants=new ToolGrantsMapper($fx->db);$setGrant=function(string $value)use($fx,$grants,$name):void {$fx->db->beginTransaction();$grants->set('user','alice',$name,$value);$fx->db->commit();};$setGrant('allow');$policy=$fx->policy($services,$grants);$access=new MailAccessGuard($policy);
        $clock=$this->createStub(\OCP\AppFramework\Utility\ITimeFactory::class);$clock->method('getTime')->willReturn(1000);$context=new ConnectionContext();
        $fx->database->dbal->executeStatement('CREATE TABLE mcp_oauth_tools (user_id TEXT, connection_id INTEGER, tool_names TEXT, tool_variants TEXT, revision INTEGER)');
        $selection=new ConnectionToolsStore($fx->db,$clock,$this->createMock(TokenOwnerGate::class),new AuthUsersMapper($fx->db),$fx->state,new OAuthClientsMapper($fx->db));$selection->save('alice',0,[$name],'0');
        $db=new \OCA\Mcp\Tests\Unit\SqliteDatabase($this,[new \OCA\Mcp\Migration\Version002700Date20261107000000()]);$journal=new OperationJournal(new \OCA\Mcp\Db\OperationsMapper($db->connection()),new \OCA\Mcp\Mcp\Confirmation\PlanState($fx->config->mock($this)),$context,$clock,new \OCA\Mcp\Tests\Support\OperationActors());
        $apps=$this->createMock(\OCP\App\IAppManager::class);$apps->method('isEnabledForUser')->willReturn(true);$apps->method('getAppVersion')->willReturn('99.0.0');$apps->method('getAppPath')->willReturn('/native-mail');$guests=$this->createMock(\OCA\Mcp\Access\Guests\GuestRestrictions::class);$guests->method('allowsApp')->willReturn(true);
        $fresh=new MailFreshAccess($policy,$apps,$fx->userManager(),$guests,$context,$selection,$journal);
        $auth=new MailOperationAuthorization($access,$native,$journal,$policy,$this->createMock(FilesScope::class),$this->createMock(FilesAccessGuard::class),$this->createMock(\OCP\Files\IRootFolder::class),new MailTagVisibility($native,$access),$this->createMock(MailDeliveryOrigin::class));
        $gateway=new MailGateway($native,access:$access);$messages=new MailMessageGateway($native,$access);
        $content=$this->getMockBuilder(MailContentReader::class)->setConstructorArgs([$native,new MailNativeBoundary($apps),$fx->config->mock($this),$fx->db,$context,static function(string $uid,int $connection,string $tool,int $account,int $mailbox)use($access):bool {$access->requireMailbox($uid,$account,$mailbox);return true;}])->onlyMethods(['available'])->getMock();$content->method('available')->willReturn(true);
        $actual=match($name){
            'mail_list_mailboxes'=>new \OCA\Mcp\Modules\Mail\Tools\ListMailboxes(new \OCA\Mcp\Modules\Mail\Mailboxes\MailboxLister($gateway,new \OCA\Mcp\Modules\Mail\Presentation\MailRows())),
            'mail_search_messages'=>new \OCA\Mcp\Modules\Mail\Tools\SearchMessages($native,$gateway,new \OCA\Mcp\Platform\Time\UserTimezone($fx->config->mock($this)),new \OCA\Mcp\Modules\Mail\Presentation\Link\MailLinks($this->createMock(\OCP\IURLGenerator::class)),$messages),
            'mail_search_drafts'=>new \OCA\Mcp\Modules\Mail\Tools\SearchDrafts(new MailContentFeatures($native,$content,$journal)),default=>null,
        };
        $calls=0;$changed=false;$withdraw=function()use($change,$writer,$fx,$selection,$setGrant,&$changed):void {if($change==='none')return;$changed=true;match($change){'scope'=>$writer->setResourceScope($fx->revision(),'root','alice','mail',[]),'grant'=>$setGrant('deny'),'selection'=>$selection->save('alice',0,[],'1')};};
        $tool=new class($actual,$name,$withdraw,$calls)implements \OCA\Mcp\Mcp\Tools\WriteTool {
            private static \OCA\Mcp\Mcp\Tools\ToolDefinition $definition;
            public function __construct(private ?\OCA\Mcp\Mcp\Tools\Tool $actual,string $name,private \Closure $withdraw,private int &$calls){foreach(\OCA\Mcp\Modules\Mail\MailToolCatalog::classes()as$class)if($class::definition()->name===$name){self::$definition=$class::definition();return;}throw new \LogicException('Missing tool definition');}
            public static function definition():\OCA\Mcp\Mcp\Tools\ToolDefinition{return self::$definition;}
            public function execute(array $args,\OCA\Mcp\Mcp\Tools\ToolContext $context):array {++$this->calls;if($this->actual===null)throw new \LogicException('Whole-account action reached under folder-only authority');$r=$this->actual->execute($args,$context);($this->withdraw)();return $r;}
            public function preview(array $args,\OCA\Mcp\Mcp\Tools\ToolContext $context):array{return $this->execute($args,$context);}
        };
        $module=new MailModule(array_fill_keys(array_keys(MailToolCatalog::definitions()),fn()=>$tool),$access,$journal,null,$auth,$fresh);
        $policy->within('alice',function()use($module,$name,$args,$initiallyAllowed,$change,$access,&$calls,&$changed):void {
            self::assertFalse($access->accountAllowed('alice',3),'Folder selection must never become whole-account authority');$denied=false;$result=null;
            try{$result=\OCA\Mcp\Mcp\Confirmation\WriteGate::isWrite(MailToolCatalog::definitions()[$name])?$module->preview($name,$args,'alice'):$module->call($name,$args,'alice');}catch(ToolFailure){$denied=true;}
            self::assertSame(!$initiallyAllowed||$change!=='none',$denied);
            if($initiallyAllowed){self::assertSame(1,$calls);self::assertSame($change!=='none',$changed);}
            if(!$denied){$data=$result['structuredContent']??json_decode($result['content'][0]['text'],true);$ids=match($name){'mail_list_mailboxes'=>array_column($data['mailboxes'],'id'),'mail_search_messages'=>array_column($data['folders'],'mailboxId'),'mail_search_drafts'=>array_column($data['items'],'mailbox_id')};self::assertSame([7],$ids);self::assertStringNotContainsString('HIDDEN_FOLDER',json_encode($data));self::assertStringNotContainsString('FOREIGN_FOLDER',json_encode($data));}
        });
    }
}
/** Native owner/metadata adapter. Configured-Drafts behavior mirrors GuardedDraftSearch's folder fence. */
final class FolderProjectionNative extends FakeMailApp {
    public function boundedMetadata(string $uid):object {$base=parent::boundedMetadata($uid);return new class($base){public function __construct(private object $base){}public function resourceInventory(string $uid):array{return $this->base->resourceInventory($uid);}public function identities(string $uid,string $kind,array $ids):array {$rows=[];foreach($ids as$id){$account=$kind==='account'?$id:($kind==='mailbox'&&$id===9?5:3);if($uid!=='alice'||!in_array($account,[3,4],true))continue;$rows[]=['id'=>$id,'account_id'=>$account,'mailbox_id'=>$kind==='mailbox'?$id:0];}return $rows;}};}
    private static function box(int $id,int $account):object{return new RecordingDouble('Mailbox',['getId'=>fn()=>$id,'getAccountId'=>fn()=>$account,'getName'=>fn()=>$id===7?'Selected Drafts':'HIDDEN_FOLDER','getDelimiter'=>fn()=>'/','getSpecialUse'=>fn()=>'[]','getUnseen'=>fn()=>0,'getMessages'=>fn()=>0,'getSelectable'=>fn()=>true,'isCached'=>fn()=>true,'hasLocks'=>fn()=>false],new \ArrayObject());}
    public function mailboxMapper(string $uid):object {return new RecordingDouble('MailboxMapper',['findAll'=>static fn(object $a):array=>match($a->getId()){3=>[self::box(7,3),self::box(8,3)],5=>[self::box(9,5)],default=>[]}],$this->journal);}
    public function mailManager(string $uid):object{return new RecordingDouble('MailManager',['getMailbox'=>static fn(string $owner,int $id):object=>self::box($id,$id===9?5:3)],$this->journal);}
    public function newSearchQuery(string $uid):object {return new RecordingDouble('SearchQuery',['addFlag'=>static fn()=>null],$this->journal);}
    public function flag(string $uid,string $constant,bool $isSet):object{return new \stdClass();}
    public function messageMapper(string $uid):object{return new RecordingDouble('MessageMapper',['findIdsByQuery'=>static fn()=>[]],$this->journal);}
    public function guardedDraftSearch(string $uid):object{return new class {public function search(string $uid,int $account,?string $subject,int $limit,?int $before,object $fence):array {if($uid!=='alice'||$account!==3)throw new ToolFailure('Owned configured Drafts folder unavailable');$fence->authorize($uid,$account,7,101);return ['items'=>[['message_id'=>101,'mailbox_id'=>7]]];}};}
}
