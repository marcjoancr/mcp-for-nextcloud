<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;
use OCA\Mcp\Modules\Forms\Access\FormsDeferredAccess;
use OCA\Mcp\Modules\Forms\Access\FormsFreshAccess;
use OCA\Mcp\Modules\Forms\Access\FormsNativeAuthorization;
use OCA\Mcp\Modules\Forms\Access\FormsOriginProof;
use OCA\Mcp\Modules\Forms\Access\FormsPrincipal;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
final class FormsTransferAuthorizationTest extends TestCase {
    private function fixture():object {
        $fx=new ScopeFixture($this);$services=$fx->services();
        $services->grants->setEligible('alice',true);$services->grants->setConnected('alice',true);$services->service->setGlobalEnabled(true);
        foreach(['transfer','continue_operations','cancel_operations','read_admin_settings','read'] as $op)$services->grants->setGrant('alice','forms',$op,true);
        foreach(['read','create'] as $op)$services->grants->setGrant('alice','files',$op,true);
        $toolGrants=new \OCA\Mcp\Db\ToolGrantsMapper($fx->db);$fx->db->beginTransaction();
        foreach(['forms_transfer','forms_continue_operation','forms_cancel_operation','forms_read_admin_settings','forms_capabilities'] as $tool)$toolGrants->set('user','alice',$tool,'allow');$fx->db->commit();
        $policy=$fx->policy($services,$toolGrants);$clock=$this->createStub(\OCP\AppFramework\Utility\ITimeFactory::class);
        $fx->database->dbal->executeStatement('CREATE TABLE mcp_oauth_tools (user_id TEXT, connection_id INTEGER, tool_names TEXT, tool_variants TEXT, revision INTEGER)');
        $selection=new \OCA\Mcp\OAuth\ConnectionToolsStore($fx->db,$clock,$this->createMock(\OCA\Mcp\OAuth\TokenOwnerGate::class),new \OCA\Mcp\Db\AuthUsersMapper($fx->db),$fx->state,new \OCA\Mcp\Db\OAuthClientsMapper($fx->db));
        $tools=['forms_transfer','forms_continue_operation','forms_cancel_operation','forms_read_admin_settings','forms_capabilities'];$selection->save('alice',0,$tools,'0');
        $apps=$this->createMock(\OCP\App\IAppManager::class);$apps->method('isEnabledForUser')->willReturn(true);$apps->method('getAppVersion')->willReturn('99.0.0');$apps->method('getAppPath')->willReturn('/native-forms');
        $guestState=new class {public bool $allowed=true;public function isWhitelistEnabled():bool{return true;}public function isAppWhitelisted(string $app):bool{return $this->allowed;}};
        $apps->method('isEnabledForAnyone')->willReturn(true);
        $guests=new \OCA\Mcp\Access\Guests\GuestRestrictions($apps,$this->createMock(\OCP\IRequest::class),static fn()=>new class{public function isGuest(string $uid):bool{return true;}},static fn()=>$guestState,static fn()=>new \stdClass(),static fn()=>new \stdClass(),new \Psr\Log\NullLogger());
        $groups=$this->createMock(\OCP\IGroupManager::class);$session=$this->createMock(\OCP\IUserSession::class);$session->method('getUser')->willReturn($fx->user('alice'));
        $state=(object)['calls'=>[],'deny'=>false];$principal=new FormsPrincipal(static function(string $uid,bool $admin)use($state):void{$state->calls[]=[$uid,$admin];if($state->deny)throw new \RuntimeException('Revoked native principal');});
        $context=new \OCA\Mcp\OAuth\ConnectionContext();$fresh=new FormsFreshAccess($policy,$apps,$fx->userManager(),$guests,$context,$selection,$groups,$session,$principal);
        $deferred=new FormsDeferredAccess($policy,$apps,$fx->userManager(),$guests,$selection,$groups,$principal);
        $actors=new \OCA\Mcp\Tests\Support\OperationActors();$proof=new FormsOriginProof(new \OCA\Mcp\Mcp\Confirmation\PlanState($fx->config->mock($this)),$actors);
        $resource=(object)['hidden'=>null,'lookups'=>0,'seen'=>[],'resolved'=>0];$source=$this->createMock(\OCP\Files\File::class);$source->method('getId')->willReturn(11);$source->method('isReadable')->willReturn(true);
        $copy=$this->createMock(\OCP\Files\File::class);$copy->method('getId')->willReturn(22);$copy->method('isReadable')->willReturn(true);
        $parent=$this->createMock(\OCP\Files\Folder::class);$parent->method('getId')->willReturn(33);$parent->method('isReadable')->willReturn(true);$parent->method('isCreatable')->willReturn(true);
        $root=$this->createMock(\OCP\Files\IRootFolder::class);$home=$this->createMock(\OCP\Files\Folder::class);$root->method('getUserFolder')->willReturnCallback(static function(string $uid)use($resource,$home){if($uid!=='alice')throw new \LogicException('Wrong resource actor');$resource->lookups++;return$home;});$home->method('getById')->willReturnCallback(static fn(int$id)=>$id===11?[$source]:[]);
        $guard=$this->createMock(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class);$guard->method('isVisible')->willReturnCallback(static function($node)use($resource){$resource->seen[]=$node->getId();return$node->getId()!==$resource->hidden;});
        $native=new FormsNativeAuthorization($proof,$fresh,$deferred,$context,$policy,new \OCA\Mcp\Modules\Forms\Attachments\FormsFileImpact($root,$guard));
        $bulk=['tool'=>'forms_transfer','required_operations'=>['transfer'],'files_operations'=>['read','create']];
        $original=$proof->create('alice',0,'forms_transfer',['transfer'],['form_id'=>4,'files_operations'=>['read','create']]);
        $control=$proof->create('alice',0,'forms_continue_operation',['continue_operations'],['form_id'=>4,'bulk_authority'=>$bulk]);
        return (object)compact('fx','services','policy','selection','tools','state','fresh','deferred','actors','proof','native','bulk','original','control','guestState','resource','copy','parent');
    }
    private function admit(object$f,?array$control=null):void{$f->native->assertTransferFiles($f->original,$control,['files'=>[['file_id'=>11]]],[$f->copy],[$f->parent],static function()use($f):array{self::assertSame([11,22,33],array_slice($f->resource->seen,-3));$f->resource->resolved++;return[['files'=>[['file_id'=>11]]],[$f->copy],[$f->parent]];});}
    public function testOriginalAndPairedCallsRecheckPrincipalAndEveryResource():void{
        $f=$this->fixture();$this->admit($f);self::assertSame([11,22,33],$f->resource->seen);self::assertCount(1,$f->state->calls);
        $f->resource->seen=[];$this->admit($f,$f->control);self::assertSame([11,22,33],$f->resource->seen);self::assertCount(2,$f->state->calls);self::assertSame(2,$f->resource->resolved);
    }
    public function testFreshAdmissionRefusesRevokedCurrentControlBeforeResolvingFiles():void{
        $f=$this->fixture();$this->admit($f,$f->control);$before=$f->resource->lookups;$f->selection->save('alice',0,array_values(array_diff($f->tools,['forms_continue_operation'])),'1');
        $denied=false;try{$this->admit($f,$f->control);}catch(ToolFailure){$denied=true;}self::assertTrue($denied);self::assertSame($before,$f->resource->lookups);
    }
    public static function resourceRevocations():array{return [[11],[22],[33]];}
    #[DataProvider('resourceRevocations')]
    public function testPairedTransferUsesCurrentSourceCopyAndActualParent(int$id):void{
        $f=$this->fixture();$this->admit($f,$f->control);$f->resource->hidden=$id;$error=null;try{$this->admit($f,$f->control);}catch(\RuntimeException$e){$error=$e->getMessage();}
        self::assertSame(match($id){11=>'Forms file effect is outside the current Files access',22=>'Forms transfer copy is outside the current Files access',33=>'Forms destination is outside the current Files access'},$error);
    }
    public function testOnlySignedTransferWithExactFormReferenceMayUseBatchCallback():void{
        foreach(['tool','form','tamper']as$case){$f=$this->fixture();$f->original=match($case){'tool'=>$f->proof->create('alice',0,'forms_capabilities',[],['form_id'=>4]),'form'=>$f->proof->create('alice',0,'forms_transfer',['transfer'],[]),'tamper'=>array_replace($f->original,['operations'=>[]])};$denied=false;try{$this->admit($f);}catch(ToolFailure){$denied=true;}self::assertTrue($denied,$case);self::assertSame(0,$f->resource->lookups);}
    }
    public function testTransferPreservesCapturedFilesOperationsWithoutAddingGenericGrants():void{
        $f=$this->fixture();foreach(['read','create']as$op)$f->services->grants->setGrant('alice','files',$op,false);
        $f->original=$f->proof->create('alice',0,'forms_transfer',['transfer'],['form_id'=>4]);$f->bulk['files_operations']=[];$f->control=$f->proof->create('alice',0,'forms_continue_operation',['continue_operations'],['form_id'=>4,'bulk_authority'=>$f->bulk]);$this->admit($f,$f->control);self::assertSame([11,22,33],$f->resource->seen);
    }
    public function testFinalResolutionMustMatchTheAuthorizedSourceProjection():void {
        $f=$this->fixture();$error=null;try{$f->native->assertTransferFiles($f->original,$f->control,['files'=>[['file_id'=>11]]],[$f->copy],[$f->parent],static fn()=>[['files'=>[]],[],[]]);}catch(\RuntimeException$e){$error=$e->getMessage();}self::assertSame('Forms transfer final resolution differs',$error);
    }

    public function testFileFreeTransferDoesNotResolveOrRequireFilesResources():void {
        $f=$this->fixture();foreach(['read','create']as$op)$f->services->grants->setGrant('alice','files',$op,false);$f->original=$f->proof->create('alice',0,'forms_transfer',['transfer'],['form_id'=>4]);$f->bulk['files_operations']=[];$f->control=$f->proof->create('alice',0,'forms_continue_operation',['continue_operations'],['form_id'=>4,'bulk_authority'=>$f->bulk]);$called=0;
        $f->native->assertTransferFiles($f->original,$f->control,['files'=>[]],[],[],static function()use(&$called):array{$called++;return[['files'=>[]],[],[]];});self::assertSame(1,$called);self::assertSame(0,$f->resource->lookups);self::assertSame([],$f->resource->seen);
    }

}
