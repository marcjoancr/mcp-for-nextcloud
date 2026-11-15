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
final class FormsAuthorityWindowTest extends TestCase {
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
        $native=new FormsNativeAuthorization($proof,$fresh,$deferred,$context,$policy,new \OCA\Mcp\Modules\Forms\Attachments\FormsFileImpact($this->createMock(\OCP\Files\IRootFolder::class),$this->createMock(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class)));
        $bulk=['tool'=>'forms_transfer','required_operations'=>['transfer'],'files_operations'=>['read','create']];
        $original=$proof->create('alice',0,'forms_transfer',['transfer'],['form_id'=>4,'files_operations'=>['read','create']]);
        $control=$proof->create('alice',0,'forms_continue_operation',['continue_operations'],['form_id'=>4,'bulk_authority'=>$bulk]);
        return (object)compact('fx','services','policy','selection','tools','state','fresh','deferred','actors','proof','native','bulk','original','control','guestState');
    }
    public function testBulkAdmissionChecksNativePrincipalOncePerFreshWindow():void {
        $f=$this->fixture();$f->deferred->assert($f->proof->verify($f->control));self::assertSame([['alice',false]],$f->state->calls);
        $f->state->calls=[];$f->fresh->assertTool('alice','forms_continue_operation',[],['bulk_authority'=>$f->bulk]);self::assertSame([['alice',false]],$f->state->calls);
    }
    public function testPairedAuthorityRechecksNativePrincipalOnEveryInvocation():void {
        $f=$this->fixture();self::assertSame('forms_transfer',$f->native->assertPair($f->original,$f->control)['tool']);
        self::assertSame([['alice',false]],$f->state->calls);$f->native->assertPair($f->original,$f->control);self::assertCount(2,$f->state->calls);
        $f->state->deny=true;$this->expectException(ToolFailure::class);$f->native->assertPair($f->original,$f->control);
    }
    public function testAdminRoleAndCapabilitiesSemanticsRemainNative():void {
        $f=$this->fixture();$f->fresh->assertTool('alice','forms_capabilities',[]);self::assertSame([],$f->state->calls);
        $f->fresh->assertTool('alice','forms_read_admin_settings',[]);self::assertSame([['alice',true]],$f->state->calls);
    }
    public function testGuestAllowlistRevocationIsReadAcrossBothFreshBoundaries():void {
        $f=$this->fixture();$f->native->assertPair($f->original,$f->control);$f->guestState->allowed=false;
        foreach(['pair','fresh'] as $kind){try{if($kind==='pair')$f->native->assertPair($f->original,$f->control);else $f->fresh->assertTool('alice','forms_transfer',[]);self::fail('Stale guest allowlist authorized a new boundary.');}catch(ToolFailure){self::assertTrue(true);}}
        $f->guestState->allowed=true;$f->native->assertPair($f->original,$f->control);self::assertTrue(true);
    }
    public function testStrongestAdminRequirementAndLaterBulkDenialArePreserved():void {
        $f=$this->fixture();$f->fresh->assertTool('alice','forms_read_admin_settings',[],['bulk_authority'=>$f->bulk]);self::assertSame([['alice',true]],$f->state->calls);
        $f->selection->save('alice',0,array_values(array_diff($f->tools,['forms_transfer'])),'1');$f->state->calls=[];
        try{$f->fresh->assertTool('alice','forms_read_admin_settings',[],['bulk_authority'=>$f->bulk]);self::fail('Admin admission skipped denied bulk tool.');}catch(ToolFailure){self::assertSame([],$f->state->calls);}
    }
    public function testSignedCancelAndControlWithoutOptionalFormAreAdmitted():void {
        $f=$this->fixture();$control=$f->proof->create('alice',0,'forms_cancel_operation',['cancel_operations'],['bulk_authority'=>$f->bulk]);
        self::assertSame('forms_transfer',$f->native->assertPair($f->original,$control)['tool']);
    }
    public static function revocations():array {return array_map(static fn($s)=>[$s],['original_selection','current_selection','original_grant','current_grant','files_grant','service','actor','tampered_original','tampered_control','other_actor','other_connection','other_form','weakened_bulk','missing_bulk','other_tool','original_action','original_operations']);}
    #[DataProvider('revocations')]
    public function testEitherProofAndBothCurrentAdmissionsRemainRequired(string $case):void {
        $f=$this->fixture();$f->native->assertPair($f->original,$f->control);
        $f->policy->within('alice',function()use($f,$case):void {
            self::assertTrue($f->policy->granted('alice','forms','transfer'));
            switch($case){
                case 'original_selection':case 'current_selection':$tool=$case==='original_selection'?'forms_transfer':'forms_continue_operation';$f->selection->save('alice',0,array_values(array_diff($f->tools,[$tool])),'1');break;
                case 'original_grant':case 'current_grant':$f->services->grants->setGrant('alice','forms',$case==='original_grant'?'transfer':'continue_operations',false);break;
                case 'files_grant':$f->services->grants->setGrant('alice','files','create',false);break;
                case 'service':$f->services->service->setGlobalEnabled(false);break;
                case 'actor':$f->actors->generation='two';break;
                case 'tampered_original':$f->original['operations']=[];break;
                case 'tampered_control':$f->control['operations']=[];break;
                case 'original_action':$f->bulk=['tool'=>'forms_delete_form','required_operations'=>['delete_form'],'files_operations'=>['read','create']];$f->control=$f->proof->create('alice',0,'forms_continue_operation',['continue_operations'],['form_id'=>4,'bulk_authority'=>$f->bulk]);break;
                case 'original_operations':$f->original=$f->proof->create('alice',0,'forms_transfer',['transfer','read'],['form_id'=>4,'files_operations'=>['read','create']]);break;
                case 'other_actor':$f->control=$f->proof->create('bob',0,'forms_continue_operation',['continue_operations'],['form_id'=>4,'bulk_authority'=>$f->bulk]);break;
                case 'missing_bulk':$f->control=$f->proof->create('alice',0,'forms_continue_operation',['continue_operations'],['form_id'=>4]);break;
                case 'other_tool':$f->control=$f->proof->create('alice',0,'forms_read_admin_settings',['read_admin_settings'],['form_id'=>4,'bulk_authority'=>$f->bulk]);break;
                case 'other_connection':$f->control=$f->proof->create('alice',5,'forms_continue_operation',['continue_operations'],['form_id'=>4,'bulk_authority'=>$f->bulk]);break;
                case 'other_form':$f->control=$f->proof->create('alice',0,'forms_continue_operation',['continue_operations'],['form_id'=>5,'bulk_authority'=>$f->bulk]);break;
                case 'weakened_bulk':$f->bulk['files_operations']=[];$f->control=$f->proof->create('alice',0,'forms_continue_operation',['continue_operations'],['form_id'=>4,'bulk_authority'=>$f->bulk]);break;
            }
            $this->expectException($case==='actor'?\OCA\Mcp\Mcp\Operations\ActorRetired::class:ToolFailure::class);$f->native->assertPair($f->original,$f->control);
        });
    }
}
