<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Db\FormsJobsMapper;
use OCA\Mcp\Db\FormsSpoolMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version002800Date20261113100000;
use OCA\Mcp\Migration\Version002800Date20261113110000;
use OCA\Mcp\Migration\Version002800Date20261113120000;
use OCA\Mcp\Modules\Forms\Exports\FormsExportJobs;
use OCA\Mcp\Modules\Forms\Exports\FormsExportRenderer;
use OCA\Mcp\Modules\Forms\Exports\FormsSpool;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
final class FormsSqlCacheBudgetTest extends TestCase {
    private SqliteDatabase $sql;private FormsSpoolMapper $budget;private FormsJobsMapper $jobs;private int $now=1000;private string $id;
    protected function setUp():void {
        $unused=$this->createMock(\OCP\IDBConnection::class);$this->id=hash('sha256','sql-result-budget');
        $this->sql=new SqliteDatabase($this,[new Version001100Date20261015000000($unused),new Version002800Date20261113100000(),new Version002800Date20261113110000(),new Version002800Date20261113120000()]);
        $this->sql->dbal->executeStatement('INSERT INTO mcp_policy_state (id,revision,lock_seq,auth_epoch) VALUES (1,0,0,0)');
        $db=$this->sql->connection();$clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturnCallback(fn():int=>$this->now);
        $crypto=$this->createMock(ICrypto::class);$crypto->method('encrypt')->willReturnCallback(static fn(string $value):string=>'cipher:'.bin2hex($value));
        $crypto->method('decrypt')->willReturnCallback(static fn(string $value):string=>hex2bin(substr($value,7)));
        $this->budget=new FormsSpoolMapper($db,$clock,new PolicyStateMapper($db),$crypto);$this->jobs=new FormsJobsMapper($db,$crypto,$this->budget);
    }
    private function create():array {
        $job=['job_id'=>$this->id,'actor_uid'=>'alice','state'=>'delivery_uncertain','version'=>1,'updated_at'=>1000,'payload'=>[
            'origin'=>['uid'=>'alice','connection'=>12],'cache_protocol'=>2,'native_runtime'=>\OCA\Mcp\Modules\Forms\Integration\FormsGuardIdentity::INSTALLED_MANIFEST_SHA256,
            'tool'=>'forms_summarize_results','arguments'=>['id'=>4],'source_revision'=>'4',
            'metadata'=>['questions'=>[['id'=>1,'text'=>'Private question']],'submissionCount'=>1,'form'=>['ownerId'=>'alice']],
            'summary'=>[['value'=>'private raw answer','count'=>1]],'parts'=>[],'file_ids'=>[7],
            'last_step'=>['key'=>'completed-step','approved_version'=>1,'outcome'=>['state'=>'succeeded','references'=>['job_id'=>$this->id]]],
            'delivered'=>['file_id'=>7,'sha256'=>'verified-output']]];
        $this->budget->reserve($this->id,'alice');self::assertTrue($this->jobs->create($job));return $this->jobs->get($this->id);
    }
    private function linkedRecoveryFixture():array {
        $old=$this->create();$next=$old;$next['version']++;
        $p=&$next['payload'];$p['tool']='forms_link_results_file';$p['origin']['tool']=$p['tool'];
        $p['arguments']=['id'=>4,'path'=>'results.csv','format'=>'csv'];$p['destination']=['path'=>'results.csv','overwrite'=>false];
        $p['metadata']['form']['id']=4;
        $p['linked_schema_digest']=\OCA\Mcp\Modules\Forms\Exports\FormsLinkedExport::schemaDigest($p['metadata']);
        $p['linked_approval']=['arguments'=>$p['arguments'],'destination'=>$p['destination'],'native_runtime'=>$p['native_runtime'],
            'source_revision'=>$p['source_revision'],'schema_digest'=>$p['linked_schema_digest']];
        $p['last_step']=['key'=>'verified-native-key','approved_version'=>1,'outcome'=>['state'=>'partial','references'=>['job_id'=>$this->id]]];
        unset($p['delivered']);self::assertTrue($this->jobs->compareAndSwap($old,$next));$this->budget->seal($this->id);
        $spool=$this->createMock(FormsSpool::class);
        foreach(['get','put','assertActive','reserve','seal'] as $method)$spool->expects(self::never())->method($method);
        $engine=new FormsExportJobs($this->jobs,$spool,new FormsExportRenderer(),static fn(array $origin):array=>$origin,fn():int=>$this->now);
        $association=str_repeat('a',64);
        $receipt=['state'=>'succeeded','operation_key'=>'verified-native-key','phases'=>['database'=>'committed','files'=>'saved_verified'],
            'references'=>['form_id'=>4,'file_id'=>7,'association_id'=>$association,'linked_export'=>$association,'source_revision'=>'4']];
        return [$engine,$receipt];
    }
    /** @dataProvider expiredLinkedStates */
    public function testExactHistoricalAcknowledgementSurvivesRealSqlExpiryAndCompaction(bool $compacted):void {
        [$engine,$receipt]=$this->linkedRecoveryFixture();
        if($compacted)$this->expire();else $this->now+=FormsSpoolMapper::RETENTION_SECONDS;
        $lookups=0;
        $outcome=$engine->reconcileLinkedDelivery('alice',$this->id,'verified-native-key',1,function(array $origin)use($receipt,&$lookups):array {
            self::assertSame(12,$origin['connection']);$lookups++;return $receipt;
        });
        self::assertSame('succeeded',$outcome['state']);self::assertSame(1,$lookups);
        $after=$this->jobs->get($this->id);self::assertSame('delivered',$after['state']);
        self::assertSame(['file_id'=>7,'association_id'=>str_repeat('a',64),'historical'=>true],$after['payload']['delivered']);
        self::assertSame('succeeded',$engine->recover('alice',$this->id,'verified-native-key',1)['state']);
        if($compacted) {
            self::assertArrayNotHasKey('metadata',$after['payload']);
            self::assertStringNotContainsString('Private question',json_encode($after['payload']));
            self::assertSame(0,(int)$this->sql->dbal->fetchOne('SELECT used_bytes FROM mcp_forms_spool'));
        }
    }
    /** @dataProvider invalidHistoricalChanges */
    public function testHistoricalSqlRouteCannotRestoreContentOrSubstituteTheApproval(string $change):void {
        [, $receipt]=$this->linkedRecoveryFixture();$this->expire();$old=$this->jobs->get($this->id);
        $file=(new \OCA\Mcp\Modules\Forms\Exports\FormsLinkedActivation())->recovered('alice',$old,'verified-native-key',$receipt);
        self::assertIsArray($file);$next=$old;$next['version']++;$next['state']='delivered';$next['updated_at']=$this->now;
        $next['payload']['delivered']=$file;
        $next['payload']['last_step']=['key'=>'verified-native-key','approved_version'=>1,
            'outcome'=>\OCA\Mcp\Modules\Forms\Exports\FormsLinkedActivation::historicalOutcome($old,$receipt,$file)];
        match($change) {
            'content'=>$next['payload']['metadata']=['answers'=>['resurrected private bytes']],
            'origin'=>$next['payload']['origin']['connection']=99,
            'key'=>$next['payload']['last_step']['key']='unapproved-key',
            'file'=>$next['payload']['delivered']['file_id']=99,
            'proof'=>$receipt['phases']['database']='unknown',
            'version'=>$next['version']++,
        };
        self::assertFalse($this->jobs->acknowledgeLinkedDelivery($old,$next,$receipt));
        self::assertSame($old,$this->jobs->get($this->id));
        self::assertSame(0,(int)$this->sql->dbal->fetchOne('SELECT used_bytes FROM mcp_forms_spool'));
    }
    public static function invalidHistoricalChanges():iterable {foreach(['content','origin','key','file','proof','version'] as $change)yield $change=>[$change];}
    public static function expiredLinkedStates():iterable {yield 'expired but not cleaned'=>[false];yield 'cleaned and SQL compacted'=>[true];}
    private function expire():void {$this->now+=FormsSpoolMapper::RETENTION_SECONDS;self::assertTrue($this->budget->retire($this->id));$this->budget->finish($this->id);}
    public function testReadOnlyRetirementProofRequiresSealedMatchingActorAndNoPendingIo():void {
        $this->create();self::assertFalse($this->budget->sealed($this->id,'alice'));
        $this->budget->seal($this->id);$before=$this->sql->dbal->fetchAssociative('SELECT * FROM mcp_forms_spool');
        self::assertTrue($this->budget->sealed($this->id,'alice'));self::assertFalse($this->budget->sealed($this->id,'bob'));
        self::assertSame($before,$this->sql->dbal->fetchAssociative('SELECT * FROM mcp_forms_spool'));
        $this->sql->dbal->executeStatement("UPDATE mcp_forms_spool SET io_token='unknown-writer'");
        self::assertFalse($this->budget->sealed($this->id,'alice'));
    }
    public function testCurrentEncryptedSqlPayloadIsChargedWithBlobReservations():void {
        $old=$this->create();$row=$this->sql->dbal->fetchAssociative('SELECT * FROM mcp_forms_spool');$cipher=$this->sql->dbal->fetchOne('SELECT payload FROM mcp_forms_jobs');
        self::assertSame(strlen($cipher),(int)$row['sql_bytes']);self::assertSame(strlen($cipher),(int)$row['used_bytes']);
        $this->budget->charge($this->id,hash('sha256','blob'),100);
        $next=$old;$next['version']++;$next['payload']['metadata']['questions'][0]['text']=str_repeat('q',500);
        self::assertTrue($this->jobs->compareAndSwap($old,$next));
        $cipher=$this->sql->dbal->fetchOne('SELECT payload FROM mcp_forms_jobs');
        self::assertSame(strlen($cipher)+100,(int)$this->sql->dbal->fetchOne('SELECT used_bytes FROM mcp_forms_spool'));
    }
    public function testExpiryRemovesGeneratedSqlContentAndPreservesOutcomeEvidence():void {
        $old=$this->create();$this->budget->seal($this->id);$this->expire();$after=$this->jobs->get($this->id);
        foreach(['summary','metadata','parts','artifact','summary_artifact','file_ids'] as $field)self::assertArrayNotHasKey($field,$after['payload']);
        self::assertSame($old['payload']['origin'],$after['payload']['origin']);self::assertSame($old['payload']['last_step'],$after['payload']['last_step']);
        self::assertSame($old['payload']['delivered'],$after['payload']['delivered']);self::assertSame($old['state'],$after['state']);
        self::assertSame($old['updated_at'],$after['updated_at']);self::assertGreaterThan($old['version'],$after['version']);
        self::assertSame(0,(int)$this->sql->dbal->fetchOne('SELECT reserved_bytes FROM mcp_forms_spool'));self::assertSame(0,(int)$this->sql->dbal->fetchOne('SELECT sql_bytes FROM mcp_forms_spool'));
        $spool=$this->createMock(FormsSpool::class);$spool->method('assertActive')->willReturnCallback(fn(string $id,string $uid)=>$this->budget->assertActive($id,$uid));
        $engine=new FormsExportJobs($this->jobs,$spool,new FormsExportRenderer(),static fn(array $origin):array=>$origin,fn():int=>$this->now);
        self::assertSame($old['payload']['last_step']['outcome'],$engine->recover('alice',$this->id,'completed-step',1));
        self::assertSame(['state'=>'pending','references'=>['job_id'=>$this->id]],$engine->admissionEvidence('alice',$this->id));
    }
    public function testExpiredReceiptUpdateCannotRestoreGeneratedContent():void {
        $this->create();$this->expire();$old=$this->jobs->get($this->id);$next=$old;$next['version']++;$next['payload']['summary']=[['value'=>'resurrected']];
        try {$this->jobs->compareAndSwap($old,$next);self::fail('Expired result content restored');}catch(\RuntimeException) {}
        self::assertSame($old,$this->jobs->get($this->id));$next=$old;$next['version']++;$next['payload']['last_step']['key']='verified-recovery';
        self::assertTrue($this->jobs->compareAndSwap($old,$next));self::assertArrayNotHasKey('summary',$this->jobs->get($this->id)['payload']);
        self::assertSame(0,(int)$this->sql->dbal->fetchOne('SELECT reserved_bytes FROM mcp_forms_spool'));
    }
    public function testEarlierExpiredCandidateCannotHideUnaccountedSqlResults():void {
        $old=$this->create();
        $this->sql->dbal->executeStatement("UPDATE mcp_forms_spool SET sql_bytes=-1,state='expired',reserved_bytes=0,used_bytes=0 WHERE job_id=?",[$this->id]);
        try {$this->budget->reserve(hash('sha256','new-admission'),'bob');self::fail('Unaccounted earlier SQL data ignored');}catch(\RuntimeException) {}
        self::assertContains($this->id,$this->budget->expiring(10));self::assertTrue($this->budget->retire($this->id));$this->budget->finish($this->id);
        self::assertArrayNotHasKey('summary',$this->jobs->get($this->id)['payload']);
        self::assertSame($old['payload']['last_step'],$this->jobs->get($this->id)['payload']['last_step']);
        $this->budget->reserve(hash('sha256','new-admission'),'bob');
    }
    public function testSqlQuotaRefusalLeavesTheAcceptedCheckpointIntact():void {
        $old=$this->create();$used=(int)$this->sql->dbal->fetchOne('SELECT used_bytes FROM mcp_forms_spool');
        $this->budget->charge($this->id,hash('sha256','near-limit'),FormsSpoolMapper::JOB_BYTES-$used-100);
        $next=$old;$next['version']++;$next['payload']['metadata']['questions'][0]['text']=str_repeat('x',1000);
        try {$this->jobs->compareAndSwap($old,$next);self::fail('Over-quota SQL checkpoint accepted');}catch(\RuntimeException) {}
        self::assertSame($old,$this->jobs->get($this->id));
    }
}
