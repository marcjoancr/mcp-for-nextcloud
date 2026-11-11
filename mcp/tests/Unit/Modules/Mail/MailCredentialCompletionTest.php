<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Db\{OperationsMapper,MailSetupWorkflowsMapper};
use OCA\Mcp\Migration\{Version002700Date20261107000000,Version002700Date20261108000000,Version002700Date20261110200000};
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Accounts\MailAccountSetup;
use OCA\Mcp\Modules\Mail\Integration\{NativeSetupEvidence,NativeAccountSetupLinks};
use OCA\Mcp\Modules\Mail\Operations\MailOperationReader;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCA\Mcp\Tests\Support\Mail\MailSettingsFixture;
use OCP\{IURLGenerator,IDBConnection};
use OCP\Security\IRemoteHostValidator;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class MailCredentialCompletionTest extends TestCase {
    use MailSettingsFixture;
    private bool $allowed=true;
    private int $now=1000;
    private int $revision=7;
    private bool $proof=true;
    private bool $failCommit=false;
    private bool $revokeInOwner=false;
    private int $registrations=0;
    private int $claims=0;
    private int $observations=0;
    private function setupService():array {
        [$gateway,$entity,$native,,$states]=$this->fixture();
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000(),new Version002700Date20261108000000(),new Version002700Date20261110200000()]);
        $db->dbal->executeStatement('CREATE TABLE native_proof (used INTEGER NOT NULL)');
        $db->dbal->executeStatement('INSERT INTO native_proof VALUES (0)');
        $connection=$db->connection();
        $clock=$this->createStub(ITimeFactory::class);$clock->method('getTime')->willReturnCallback(fn():int=>$this->now);
        $context=new ConnectionContext();$context->set(12);
        $journal=new OperationJournal(new OperationsMapper($connection),$states,$context,$clock, new \OCA\Mcp\Tests\Support\OperationActors());
        $store=new MailSetupWorkflowsMapper($connection);
        $urls=$this->createStub(IURLGenerator::class);$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn(string $r):string=>'https://cloud.example.test/apps/mail/'.($r==='mail.page.setup'?'setup':''));
        $hosts=$this->createStub(IRemoteHostValidator::class);$hosts->method('isValid')->willReturn(true);
        $evidence=$this->createMock(NativeSetupEvidence::class);
        $evidence->method('available')->willReturn(true);
        $evidence->method('register')->willReturnCallback(function(string $uid,string $scope,string $source,string $kind,?int $id,array $intent,int $expires)use($journal):string {
            self::assertSame('alice',$uid);self::assertSame($journal->scopeKey($uid),$scope);self::assertSame($journal->activeId($uid),$source);
            self::assertSame('configure_connection',$kind);self::assertSame(4,$id);self::assertSame([],$intent);self::assertSame(87400,$expires);
            ++$this->registrations;return str_repeat('a',64);
        });
        $evidence->method('observe')->willReturnCallback(function(string $uid,string $id,int $account,string $scope,string $source):?array {
            ++$this->observations;self::assertSame(str_repeat('a',64),$id);self::assertSame(4,$account);
            return $this->proof?['status'=>'native_write_observed','account_id'=>4,'revision'=>$this->revision,'kind'=>'password','protocols'=>['smtp'],'saved_at'=>1000,'credential_change'=>'unverified']:null;
        });
        $evidence->method('describe')->willReturn(['kind'=>'configure_connection','target_account_id'=>4,'expires_at'=>87400]);
        $evidence->method('withCompletion')->willReturnCallback(function(string $uid,\Closure $work):mixed {if($this->revokeInOwner)$this->allowed=false;return $work();});
        $evidence->method('claim')->willReturnCallback(function(IDBConnection $actual,string $uid,string $id,int $account,int $revision,string $scope,string $source,string $completion)use($connection,$db):void {
            self::assertSame($connection,$actual);self::assertTrue($actual->inTransaction());
            self::assertSame($this->revision,$revision);++$this->claims;
            $db->dbal->executeStatement('UPDATE native_proof SET used=1');
        });
        $evidence->method('assertCompletion')->willReturnCallback(function(IDBConnection $actual,string $uid)use($connection):void {
            self::assertSame($connection,$actual);self::assertTrue($actual->inTransaction());
            if($this->failCommit)throw new \RuntimeException('Simulated pre-commit session failure.');
        });
        $setup=new MailAccountSetup($gateway,$store,$journal,$states,$clock,new NativeAccountSetupLinks($urls,$hosts),fn(string $uid,string $tool):bool=>$this->allowed,$evidence);
        return [$setup,$journal,$store,$db,$context,$native];
    }
    private function begin(MailAccountSetup $s,OperationJournal $j):array {
        $args=['account_id'=>4,'intent'=>[]];$p=$j->plan('mail_configure_connection','alice',$args,$s->preview($args,'alice'));
        $args+=['operation_id'=>$p['operation_id'],'plan_state'=>$p['plan_state']];
        return [$j->execute('mail_configure_connection','alice',$args,fn():array=>$s->begin($args,'alice')),$args];
    }
    private function completion(MailAccountSetup $s,OperationJournal $j,string $source):array {
        $args=['setup_operation_id'=>$source,'account_id'=>4,'intent'=>[]];
        $p=$j->plan('mail_complete_account_setup','alice',$args,$s->previewCompletion($args,'alice'));
        return $args+['operation_id'=>$p['operation_id'],'plan_state'=>$p['plan_state']];
    }
    public function testSameValueWriteCanCompleteAndConsumeExactlyOnceWithStatusRecovery():void {
        [$s,$j,$store,$db,,$native]=$this->setupService();[$start,$source]=$this->begin($s,$j);
        $reader=new MailOperationReader($j,fn(string $uid,string $tool):bool=>$this->allowed,fn(string $uid,string $id):?array=>$s->pendingNativeEntry($uid,$id));
        self::assertSame($start['url'],$reader->get('alice',$source['operation_id'])['native_setup']['url']);
        self::assertStringContainsString('?mcpSetup='.str_repeat('a',64),$start['url']);
        self::assertSame(str_repeat('a',64),$store->get($j->scopeKey('alice'),$source['operation_id'])['native_context_id']);
        $j->execute('mail_configure_connection','alice',$source,fn():array=>throw new \LogicException('Re-admission'));
        self::assertSame(1,$this->registrations);self::assertSame(0,$this->claims);
        $args=$this->completion($s,$j,$source['operation_id']);
        $done=$j->execute('mail_complete_account_setup','alice',$args,fn():array=>$s->complete($args,'alice'));
        self::assertSame('succeeded',$done['operation']['state']);self::assertSame('native_write_observed',$done['credential_write']['status']);
        self::assertSame('unverified',$done['credential_write']['credential_change']);self::assertSame(1,$this->claims);
        self::assertSame(1,(int)$db->dbal->fetchOne('SELECT used FROM native_proof'));
        self::assertSame('succeeded',$j->get('alice',$source['operation_id'])['state']);self::assertSame(0,$native->writes);
        self::assertArrayNotHasKey('native_setup',$reader->get('alice',$source['operation_id']));
        self::assertTrue($j->execute('mail_complete_account_setup','alice',$args,fn():array=>throw new \LogicException('Reclaim'))['operation']['replayed']);
        self::assertSame(1,$this->claims);self::assertStringNotContainsString('SECRET',json_encode($db->dbal->fetchAllAssociative('SELECT * FROM mcp_mail_setup')));
    }
    public function testPendingLinkCanRecoverTheAssistantToRepairUnsafeSettingsWithoutApprovingThem():void {
        [$s,$j,,,, $native]=$this->setupService();[$start,$source]=$this->begin($s,$j);
        $native->accounts[4]->getMailAccount()->setInboundSslMode('none');
        self::assertSame($start['url'],$s->pendingNativeEntry('alice',$source['operation_id'])['url']??null);
        $args=['setup_operation_id'=>$source['operation_id'],'account_id'=>4,'intent'=>[]];
        try{$s->previewCompletion($args,'alice');self::fail('Unsafe settings were approved');}catch(ToolFailure){}
        self::assertSame(0,$this->observations);self::assertSame(0,$this->claims);self::assertSame(0,$native->writes);
        self::assertSame(1,$this->registrations);
    }
    public function testDiagnosticOrOldTokenWithoutManualProofCannotComplete():void {
        [$s,$j]=$this->setupService();[, $source]=$this->begin($s,$j);$this->proof=false;
        $args=['setup_operation_id'=>$source['operation_id'],'account_id'=>4,'intent'=>[]];
        self::assertSame('unverified',$s->diagnosticContext($args,'alice')['credential_change']);
        self::assertSame(0,$this->observations);$this->expectException(ToolFailure::class);$s->previewCompletion($args,'alice');
    }
    public function testInterveningCredentialRevisionInvalidatesApprovedCompletion():void {
        [$s,$j]=$this->setupService();[, $source]=$this->begin($s,$j);$args=$this->completion($s,$j,$source['operation_id']);++$this->revision;
        try{$j->execute('mail_complete_account_setup','alice',$args,fn():array=>$s->complete($args,'alice'));self::fail('Stale revision consumed');}catch(PlanChanged){}
        self::assertSame(0,$this->claims);
    }
    public function testProofConsumptionWorkflowAndSourceRollbackTogetherAndNeverRetry():void {
        [$s,$j,$store,$db]=$this->setupService();[, $source]=$this->begin($s,$j);$args=$this->completion($s,$j,$source['operation_id']);$this->failCommit=true;
        $failed=$j->execute('mail_complete_account_setup','alice',$args,fn():array=>$s->complete($args,'alice'));self::assertSame('uncertain',$failed['operation']['state']);
        self::assertSame(0,(int)$db->dbal->fetchOne('SELECT used FROM native_proof'));
        self::assertSame('pending',$store->get($j->scopeKey('alice'),$source['operation_id'])['phase']);
        self::assertSame('awaiting_user',$j->get('alice',$source['operation_id'])['state']);
        self::assertSame('uncertain',$j->get('alice',$args['operation_id'])['state']);
        $j->execute('mail_complete_account_setup','alice',$args,fn():array=>throw new \LogicException('Uncertain completion retried'));
        self::assertSame(1,$this->claims);
    }
    public function testLostCommitAcknowledgementNeverReclaimsAlreadyConsumedProof():void {
        [$s,$j,$store,$db]=$this->setupService();[, $source]=$this->begin($s,$j);$args=$this->completion($s,$j,$source['operation_id']);
        $db->afterCommit=static fn()=>throw new \RuntimeException('Lost acknowledgement after commit.');
        $failed=$j->execute('mail_complete_account_setup','alice',$args,fn():array=>$s->complete($args,'alice'));
        self::assertSame('uncertain',$failed['operation']['state']);
        self::assertSame(1,(int)$db->dbal->fetchOne('SELECT used FROM native_proof'));
        self::assertSame('completed',$store->get($j->scopeKey('alice'),$source['operation_id'])['phase']);
        self::assertSame('succeeded',$j->get('alice',$source['operation_id'])['state']);
        self::assertNull($s->pendingNativeEntry('alice',$source['operation_id']));
        $j->execute('mail_complete_account_setup','alice',$args,fn():array=>throw new \LogicException('Lost acknowledgement retried'));
        self::assertSame(1,$this->claims);
    }
    public function testRevocationAtOwnerAdmissionRefusesBeforeClaim():void {
        [$s,$j,$store]=$this->setupService();[, $source]=$this->begin($s,$j);$args=$this->completion($s,$j,$source['operation_id']);$this->revokeInOwner=true;
        try{$j->execute('mail_complete_account_setup','alice',$args,fn():array=>$s->complete($args,'alice'));self::fail('Revocation ignored');}catch(ToolFailure){}
        self::assertSame(0,$this->claims);self::assertSame('pending',$store->get($j->scopeKey('alice'),$source['operation_id'])['phase']);
    }
    public function testForeignExpiredAndRevokedSourcesNeverObserveOrRecoverLink():void {
        [$s,$j,,,$context]=$this->setupService();[, $source]=$this->begin($s,$j);
        $args=['setup_operation_id'=>$source['operation_id'],'account_id'=>4,'intent'=>[]];
        foreach(['foreign','expired','revoked','intent'] as $case){
            $this->allowed=true;$this->now=1000;$context->set(12);$a=$args;
            if($case==='foreign')$context->set(13);
            if($case==='expired')$this->now=90000;
            if($case==='revoked')$this->allowed=false;
            if($case==='intent')$a['intent']=['name'=>'Other'];
            try{$s->previewCompletion($a,'alice');self::fail('Invalid source observed');}catch(ToolFailure){}
            if($case!=='intent')self::assertNull($s->pendingNativeEntry('alice',$source['operation_id']));
        }
        self::assertSame(0,$this->observations);self::assertSame(0,$this->claims);
    }
}
