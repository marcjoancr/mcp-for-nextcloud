<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Db\{OperationsMapper,MailSetupWorkflowsMapper};
use OCA\Mcp\Migration\{Version002700Date20261107000000,Version002700Date20261108000000};
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Modules\Mail\Accounts\MailAccountSetup;
use OCA\Mcp\Modules\Mail\Integration\NativeAccountSetupLinks;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCA\Mcp\Tests\Support\Mail\{MailSettingsFixture,SettingsNativeAccount};
use OCP\{IURLGenerator};
use OCP\Security\IRemoteHostValidator;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class MailAccountSetupTest extends TestCase {
    use MailSettingsFixture;
    use \OCA\Mcp\Tests\Support\Mail\MailProjectionFixture;
    private int $now=1000;
    private bool $allowed=true;
    private bool $creationAllowed=true;
    private function setupService(): array {
        [$gateway,$entity,$native,, $states]=$this->fixture($this->creationAllowed);
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000(),new Version002700Date20261108000000()]);
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturnCallback(fn():int=>$this->now);
        $context=new ConnectionContext();$context->set(12);
        $journal=new OperationJournal(new OperationsMapper($db->connection()),$states,$context,$clock, new \OCA\Mcp\Tests\Support\OperationActors());
        $store=new MailSetupWorkflowsMapper($db->connection());
        $urls=$this->createMock(IURLGenerator::class);$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn(string $r):string=>'https://cloud.example.test/apps/mail/'.($r==='mail.page.setup'?'setup':''));
        $hosts=$this->createMock(IRemoteHostValidator::class);$hosts->method('isValid')->willReturn(true);
        $links=new NativeAccountSetupLinks($urls,$hosts);
        $setup=new MailAccountSetup($gateway,$store,$journal,$states,$clock,$links,fn(string $uid,string $tool):bool=>$this->allowed);
        return [$setup,$journal,$store,$native,$entity,$db,$context];
    }
    private function begin(MailAccountSetup $s,OperationJournal $j,array $args,string $tool='mail_add_account'): array {
        $p=$j->plan($tool,'alice',$args,$s->preview($args,'alice'));
        $confirmed=$args+['operation_id'=>$p['operation_id'],'plan_state'=>$p['plan_state']];
        return [$j->execute($tool,'alice',$confirmed,fn():array=>$this->projectedEffect($j,fn():array=>$s->begin($confirmed,'alice'))),$confirmed];
    }
    public function testRepeatedBeginAndStatusNeverCreateAccountsOrStoreProfiles(): void {
        [$s,$j,$store,$native,,$db]=$this->setupService();$args=['intent'=>['email'=>'new@example.test']];
        [$r,$confirmed]=$this->begin($s,$j,$args);$again=$j->execute('mail_add_account','alice',$confirmed,fn():array=>throw new \LogicException('Should not re-enter setup'));
        self::assertSame('awaiting_user',$r['operation']['state']);self::assertTrue($again['operation']['replayed']);
        self::assertSame('https://cloud.example.test/apps/mail/setup',$r['url']);self::assertSame(0,$native->writes);
        $rows=$db->dbal->fetchAllAssociative('SELECT * FROM mcp_mail_setup');self::assertStringNotContainsString('new@example.test',json_encode($rows));self::assertStringNotContainsString('SECRET',json_encode($rows));
        $this->now=90000;self::assertSame('expired',$j->get('alice',$confirmed['operation_id'])['state']);
    }
    public function testExplicitNewAccountBindingCompletesTheSourceAndNeverWritesNativeAccount(): void {
        [$s,$j,$store,$native,$entity]=$this->setupService();$intent=['email'=>'new@example.test'];[$start,$original]=$this->begin($s,$j,['intent'=>$intent]);
        $new=clone $entity;$new->setId(5);$new->setEmail('new@example.test');$native->accounts[5]=new SettingsNativeAccount($new);
        $args=['setup_operation_id'=>$original['operation_id'],'account_id'=>5,'intent'=>$intent];
        $p=$j->plan('mail_complete_account_setup','alice',$args,$s->previewCompletion($args,'alice'));$args+=['operation_id'=>$p['operation_id'],'plan_state'=>$p['plan_state']];
        $done=$j->execute('mail_complete_account_setup','alice',$args,fn():array=>$s->complete($args,'alice'));
        self::assertSame('succeeded',$done['operation']['state']);self::assertSame(5,$done['operation']['resource_refs']['account_id']);
        self::assertSame('observed_in_mail',$j->get('alice',$original['operation_id'])['native_state']);self::assertSame(0,$native->writes);
        self::assertTrue($j->execute('mail_complete_account_setup','alice',$args,fn():array=>throw new \LogicException('Binding replayed'))['operation']['replayed']);
    }
    public function testExistingAccountAndRevokedOriginalPermissionCannotBeBound(): void {
        [$s,$j]=$this->setupService();[$r,$original]=$this->begin($s,$j,['intent'=>['email'=>'alice@example.test']]);
        $args=['setup_operation_id'=>$original['operation_id'],'account_id'=>4,'intent'=>['email'=>'alice@example.test']];
        try{$s->previewCompletion($args,'alice');self::fail('Old account bound as new');}catch(ToolFailure){}
        $this->allowed=false;$this->expectException(ToolFailure::class);$s->previewCompletion($args,'alice');
    }
    public function testCredentialOnlyNativeChangeCannotBeInferredFromAnUnchangedProfile(): void {
        [$s,$j]=$this->setupService();[$r,$original]=$this->begin($s,$j,['account_id'=>4,'intent'=>[]],'mail_configure_connection');
        $this->expectException(ToolFailure::class);$s->previewCompletion(['setup_operation_id'=>$original['operation_id'],'account_id'=>4,'intent'=>[]],'alice');
    }
    public function testChangedObservedRevisionRequiresAFreshCompletionPlan(): void {
        [$s,$j,$store,$native,$entity]=$this->setupService();$intent=['smtp'=>['port'=>465]];[$r,$original]=$this->begin($s,$j,['account_id'=>4,'intent'=>$intent],'mail_configure_connection');$entity->setOutboundPort(465);
        $args=['setup_operation_id'=>$original['operation_id'],'account_id'=>4,'intent'=>$intent];$p=$j->plan('mail_complete_account_setup','alice',$args,$s->previewCompletion($args,'alice'));
        $entity->setName('Changed in native Mail');$args+=['operation_id'=>$p['operation_id'],'plan_state'=>$p['plan_state']];
        $this->expectException(PlanChanged::class);
        $r=$j->execute('mail_complete_account_setup','alice',$args,fn():array=>$s->complete($args,'alice'));
        self::fail('Stale completion should throw PlanChanged.');
    }
    public function testForeignConnectionAndExpiredWorkflowsCannotComplete():void {
        [$s,$j,, $native,$entity,, $context]=$this->setupService();$intent=['email'=>'new@example.test'];
        [$start,$original]=$this->begin($s,$j,['intent'=>$intent]);
        $new=clone $entity;$new->setId(5);$new->setEmail('new@example.test');$native->accounts[5]=new SettingsNativeAccount($new);
        $args=['setup_operation_id'=>$original['operation_id'],'account_id'=>5,'intent'=>$intent];
        $context->set(13);
        try{$s->previewCompletion($args,'alice');self::fail('Foreign connection completed setup');}catch(ToolFailure){}
        $context->set(12);$this->now=90000;
        try{$s->previewCompletion($args,'alice');self::fail('Expired workflow completed');}catch(ToolFailure){}
        self::assertSame(0,$native->writes);
    }
    public function testAdminDisabledCreationAndProvisionedConfigurationAreRefused():void {
        $this->creationAllowed=false;[$s,$j,, $native,$entity]=$this->setupService();
        try{$s->preview(['intent'=>['email'=>'new@example.test']],'alice');self::fail('Administrator policy bypassed');}catch(ToolFailure){}
        $entity->setProvisioningId(9);
        try{$s->preview(['account_id'=>4,'intent'=>[]],'alice');self::fail('Provisioned account editable');}catch(ToolFailure){}
        self::assertSame(0,$native->writes);
    }

    public function testRevokedPreflightDoesNotLeaveAnUncertainAccountLock():void {
        [$s,$j,$store,$native,$entity]=$this->setupService();$intent=['email'=>'new@example.test'];
        [, $original]=$this->begin($s,$j,['intent'=>$intent]);
        $new=clone $entity;$new->setId(5);$new->setEmail('new@example.test');$native->accounts[5]=new SettingsNativeAccount($new);
        $args=['setup_operation_id'=>$original['operation_id'],'account_id'=>5,'intent'=>$intent];
        $p=$j->plan('mail_complete_account_setup','alice',$args,$s->previewCompletion($args,'alice'));
        $args+=['operation_id'=>$p['operation_id'],'plan_state'=>$p['plan_state']];$this->allowed=false;
        try{$j->execute('mail_complete_account_setup','alice',$args,fn():array=>$s->complete($args,'alice'));}catch(ToolFailure){}
        self::assertSame('failed_before_effect',$j->get('alice',$p['operation_id'])['state']);
        self::assertSame('pending',$store->get($j->scopeKey('alice'),$original['operation_id'])['phase']);
        $this->allowed=true;$next=['account_id'=>5];$q=$j->plan('mail_write_signature','alice',$next,['plan_state'=>'fresh']);
        $r=$j->execute('mail_write_signature','alice',$next+['operation_id'=>$q['operation_id'],'plan_state'=>'fresh'],static fn():array=>['account_id'=>5]);
        self::assertSame('succeeded',$r['operation']['state']);self::assertSame(0,$native->writes);
    }
    public function testCompletionReplayRechecksItsSourcePermissionWithoutRepeatingBinding():void {
        [$s,$j,, $native,$entity,,$context]=$this->setupService();$fresh=$this->projectionAccess($j,$context);$intent=['email'=>'new@example.test'];
        [, $original]=$this->begin($s,$j,['intent'=>$intent]);
        $new=clone $entity;$new->setId(5);$new->setEmail('new@example.test');$native->accounts[5]=new SettingsNativeAccount($new);
        $reader=new \OCA\Mcp\Modules\Mail\Operations\MailOperationReader($j,fn(string $uid,string $tool):bool=>$this->allowed||$tool==='mail_complete_account_setup',fresh:$fresh);
        $factories=array_fill_keys(array_keys(\OCA\Mcp\Modules\Mail\MailToolCatalog::definitions()),static fn()=>throw new \LogicException('Unused tool'));
        $factories['mail_complete_account_setup']=static fn()=>new \OCA\Mcp\Modules\Mail\Tools\CompleteAccountSetup($s);
        $module=new \OCA\Mcp\Modules\Mail\MailModule($factories, new \OCA\Mcp\Modules\Mail\Access\MailAccessGuard(new \OCA\Mcp\Tests\Support\Dav\ScopeStub()),$j,$reader,$this->projectionAuthorization($j),$fresh);
        $args=['setup_operation_id'=>$original['operation_id'],'account_id'=>5,'intent'=>$intent];
        $p=$module->preview('mail_complete_account_setup',$args,'alice');
        $args+=['operation_id'=>$p['operation_id'],'plan_state'=>$p['plan_state'],'confirm'=>true];
        $module->call('mail_complete_account_setup',$args,'alice');$this->allowed=false;
        try{$reader->get('alice',$p['operation_id']);self::fail('Status bypassed revocation');}catch(ToolFailure){}
        $this->expectException(ToolFailure::class);$module->call('mail_complete_account_setup',$args,'alice');
    }

    public function testUnchangedCredentialsCanBeObservedForDiagnosticsWithoutCompletingSetup():void {
        [$s,$j,,$native,$entity]=$this->setupService();
        [$r,$original]=$this->begin($s,$j,['account_id'=>4,'intent'=>[]],'mail_configure_connection');
        $args=['setup_operation_id'=>$original['operation_id'],'account_id'=>4,'intent'=>[]];
        $context=$s->diagnosticContext($args,'alice');
        self::assertSame($original['operation_id'],$context['source_operation_id']);
        self::assertSame('mail_configure_connection',$context['source_tool']);
        self::assertSame('unverified',$context['credential_change']);
        $entity->setInboundPassword('NEW-SECRET');
        self::assertSame($context,$s->diagnosticContext($args,'alice'),'Secret changes cannot masquerade as non-secret authorization revision');
        self::assertSame(0,$native->writes);self::assertSame('awaiting_user',$j->get('alice',$original['operation_id'])['state']);
        try{$s->previewCompletion($args,'alice');self::fail('Credential change falsely completed');}catch(ToolFailure){}
        self::assertStringNotContainsString('SECRET',json_encode($context));
    }
    public function testDiagnosticLinkStillRequiresCurrentIntentSourceConnectionAndExpiry():void {
        [$s,$j,,,,,$connection]=$this->setupService();
        [$r,$original]=$this->begin($s,$j,['account_id'=>4,'intent'=>[]],'mail_configure_connection');
        $args=['setup_operation_id'=>$original['operation_id'],'account_id'=>4,'intent'=>[]];
        foreach(['intent','connection','revoked','expired'] as $case){
            $a=$args;$this->allowed=true;$this->now=1000;$connection->set(12);
            if($case==='intent')$a['intent']=['name'=>'Other'];
            if($case==='connection')$connection->set(99);
            if($case==='revoked')$this->allowed=false;
            if($case==='expired')$this->now=100000;
            try{$s->diagnosticContext($a,'alice');self::fail('Diagnostic admitted '.$case);}catch(ToolFailure $e){self::assertNotEmpty($e->getMessage());}
        }
    }

    public function testOAuthTokenPresenceWithoutANonSecretRevisionDoesNotProveFreshConsent():void {
        [$s,$j,,$native,$entity]=$this->setupService();$entity->setAuthMethod('xoauth2');
        [$r,$original]=$this->begin($s,$j,['account_id'=>4,'intent'=>[]],'mail_configure_connection');
        $args=['setup_operation_id'=>$original['operation_id'],'account_id'=>4,'intent'=>[]];
        $before=$s->diagnosticContext($args,'alice');$entity->setOauthAccessToken('NEW-SECRET-TOKEN');
        self::assertSame($before,$s->diagnosticContext($args,'alice'));self::assertSame('unverified',$before['credential_change']);
        self::assertSame(0,$native->writes);$this->expectException(ToolFailure::class);$s->previewCompletion($args,'alice');
    }

}
