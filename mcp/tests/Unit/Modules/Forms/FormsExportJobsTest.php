<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Exports\{FormsExportJobs,FormsJobStore,FormsSpool,FormsExportRenderer};
use PHPUnit\Framework\TestCase;
final class FormsExportJobsTest extends TestCase {
    private array $jobs=[];private array $parts=[];private int $revision=4;private array $source=[];private FormsExportJobs $engine;private bool $filesAllowed=true;private int $now=1000;private float $seconds=0;private bool $slowReads=false;private bool $slowSeal=false;private bool $quotaDenied=false;private bool $cacheLive=true;private bool $originAllowed=true;private bool $requireCheckpointBeforeSeal=false;private bool $garbageReady=true;
    protected function setUp():void {
        $store=$this->createMock(FormsJobStore::class);
        $store->method('get')->willReturnCallback(fn(string $id):?array=>$this->jobs[$id]??null);
        $store->method('create')->willReturnCallback(function(array $job):bool {if(isset($this->jobs[$job['job_id']]))return false;$this->jobs[$job['job_id']]=$job;return true;});
        $store->method('compareAndSwap')->willReturnCallback(function(array $old,array $next):bool {if(($this->jobs[$old['job_id']]??null)!==$old)return false;$this->jobs[$old['job_id']]=$next;return true;});
        $spool=$this->createMock(FormsSpool::class);
        $spool->method('assertActive')->willReturnCallback(function():void{if(!$this->cacheLive)throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('cache expired');});
        $spool->method('retain')->willReturnCallback(fn():bool=>$this->garbageReady);
        $spool->method('reserve')->willReturnCallback(function():void{if($this->quotaDenied)throw new \RuntimeException('quota unavailable');});
        $spool->method('seal')->willReturnCallback(function(string $id):void{if($this->requireCheckpointBeforeSeal)self::assertTrue($this->jobs[$id]['payload']['collection_complete']??false);if($this->slowSeal)$this->seconds+=24;});
        $spool->method('put')->willReturnCallback(function(string $job,string $content):array {$key=hash('sha256',$content);$this->parts[$job.$key]=$content;return ['key'=>$key,'bytes'=>strlen($content)];});
        $spool->method('get')->willReturnCallback(function(string $job,array $part):string{if($this->slowReads)$this->seconds+=24;return $this->parts[$job.$part['key']];});
        $this->engine=new FormsExportJobs($store,$spool,new FormsExportRenderer(),function(array $o):array{if(!$this->originAllowed)throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('original authority revoked');return $o;},fn():int=>$this->now,function(string $uid,array $ids):void{if(!$this->filesAllowed&&in_array(90,$ids,true))throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Files access revoked');},fn():float=>$this->seconds);
        for($i=1;$i<=205;$i++)$this->source[]=['id'=>$i,'timestamp'=>$i,'answers'=>[['questionId'=>1,'text'=>'answer '.$i]]];
    }
    private function native(string $action,array $args):array {
        if($action==='snapshot')return ['revision'=>(string)$this->revision,'form'=>['id'=>4,'ownerId'=>'alice']];
        if($action==='forms_results_metadata')return ['form'=>['id'=>4,'isAnonymous'=>false,'ownerId'=>'alice'],'questions'=>[['id'=>1,'text'=>'Text','type'=>'short']],'submissionCount'=>count($this->source),'timeZone'=>'UTC'];
        $rows=array_values(array_filter($this->source,fn(array $r):bool=>$r['id']>($args['after_id']??0)));$more=count($rows)>$args['limit'];$rows=array_slice($rows,0,$args['limit']);
        return ['submissions'=>$rows,'hasMore'=>$more,'nextId'=>$rows===[]?$args['after_id']:end($rows)['id']];
    }
    private function start():array {
        $preview=$this->engine->preview('alice','forms_download_results',['id'=>4,'format'=>'csv'],['revision'=>'4','form'=>['id'=>4,'ownerId'=>'alice']],$this->native(...));
        return $this->engine->start('alice','forms_download_results',['id'=>4,'format'=>'csv'],$preview,'job-key',['uid'=>'alice','connection'=>12],$this->native(...));
    }
    /** @dataProvider deliveryInvalidations */
    public function testSinkRechecksClaimCacheAndSourceAfterWaiting(string $change):void {
        $this->source=array_slice($this->source,0,1);$id=$this->start()['references']['job_id'];
        $this->engine->step('alice',$id,$this->native(...));$writes=0;
        try {
            $this->engine->deliver('alice',$id,$this->native(...),function(string $content,array $claim)use($change,&$writes):array {
                // Represents a destination waiting for its actual storage lock.
                match($change) {
                    'source'=>$this->revision++,
                    'cache'=>$this->cacheLive=false,
                    'claim'=>$this->jobs[$claim['job_id']]['version']++,
                };
                $this->engine->assertDelivery('alice',$claim,$this->native(...));
                $writes++;return ['file_id'=>7];
            });
            self::fail('A changed admission must stop the locked destination write.');
        } catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure) {self::assertSame(0,$writes);}
    }
    public static function deliveryInvalidations():iterable {
        yield 'source changed while waiting'=>['source'];
        yield 'cache expired while waiting'=>['cache'];
        yield 'claim invalidated while waiting'=>['claim'];
    }
    public function testSinkAcceptsItsUnchangedDeliveryClaim():void {
        $this->source=array_slice($this->source,0,1);$id=$this->start()['references']['job_id'];
        $this->engine->step('alice',$id,$this->native(...));
        $done=$this->engine->deliver('alice',$id,$this->native(...),function(string $content,array $claim):array {
            $this->engine->assertDelivery('alice',$claim,$this->native(...));return ['file_id'=>7];
        });
        self::assertSame(7,$done['file']['file_id']);
    }
    public function testQuotaRefusalIsKnownBeforeAnyJobOrFileEffect():void {
        $this->quotaDenied=true;
        try {$this->start();self::fail('Over-quota job admitted');}
        catch(\OCA\Mcp\Modules\Forms\Execution\FormsBeforeEffect) {self::assertSame([],$this->jobs);self::assertSame([],$this->parts);}
    }
    public function testFullExportIncludesEverySnapshotRowOnce():void {
        $first=$this->start();self::assertSame('pending',$first['state']);$job=$first['references']['job_id'];
        $a=$this->engine->step('alice',$job,$this->native(...));self::assertSame(100,$a['done']);self::assertFalse($a['complete']);
        $b=$this->engine->step('alice',$job,$this->native(...));self::assertSame(200,$b['done']);
        $c=$this->engine->step('alice',$job,$this->native(...));self::assertSame(205,$c['done']);self::assertTrue($c['complete']);
        $content=$this->engine->content('alice',$job);
        $f=fopen('php://memory','w+');fwrite($f,$content);rewind($f);fgetcsv($f,0,',','"','');fgetcsv($f,0,',','"','');$ids=[];while(($r=fgetcsv($f,0,',','"',''))!==false)$ids[]=(int)$r[0];fclose($f);
        self::assertSame(range(1,205),$ids);
    }
    public function testFinalizationDeadlinePreservesRowsAndAllowsANewBoundedStep():void {
        $id=$this->start()['references']['job_id'];
        $this->engine->step('alice',$id,$this->native(...));$this->engine->step('alice',$id,$this->native(...));
        $this->slowReads=true;$limited=$this->engine->step('alice',$id,$this->native(...));
        self::assertFalse($limited['complete']);self::assertSame('assembling',$limited['state']);
        self::assertSame(205,$limited['done']);self::assertTrue($limited['continuation']);
        $this->slowReads=false;$resumed=$this->engine->step('alice',$id,$this->native(...));
        self::assertTrue($resumed['complete']);self::assertSame(205,$resumed['done']);
    }
    public function testCacheSealingMustFinishWithinTheStepBeforeReadiness():void {
        $this->source=array_slice($this->source,0,1);$id=$this->start()['references']['job_id'];$this->slowSeal=true;
        $limited=$this->engine->step('alice',$id,$this->native(...));
        self::assertFalse($limited['complete']);self::assertSame('assembling',$limited['state']);self::assertSame(1,$limited['done']);
        $this->slowSeal=false;self::assertTrue($this->engine->step('alice',$id,$this->native(...))['complete']);
    }
    public function testExpiredCacheCannotHideDurableStepOutcomeOrAllowContent():void {
        $id=$this->start()['references']['job_id'];
        for($i=0;$i<3;$i++)$this->engine->step('alice',$id,$this->native(...));
        $version=$this->jobs[$id]['version'];
        $this->engine->deliver('alice',$id,$this->native(...),static fn():array=>['file_id'=>7],'delivered-before-expiry',$version);
        $this->cacheLive=false;
        self::assertSame(['state'=>'succeeded','references'=>['job_id'=>$id]],$this->engine->recover('alice',$id,'delivered-before-expiry',$version));
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$this->engine->content('alice',$id);
    }
    public function testExpiredCacheKeepsJobAdmissionEvidenceUnderOriginalCurrentAuthority():void {
        $id=$this->start()['references']['job_id'];$this->cacheLive=false;
        self::assertSame(['state'=>'pending','references'=>['job_id'=>$id]],$this->engine->admissionEvidence('alice',$id));
        $this->originAllowed=false;$this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->engine->admissionEvidence('alice',$id);
    }
    public function testSummaryCheckpointsUseChargedArtifactsInsteadOfSqlAnswerBuckets():void {
        $args=['id'=>4];$preview=$this->engine->preview('alice','forms_summarize_results',$args,['revision'=>'4'],$this->native(...));
        $id=$this->engine->start('alice','forms_summarize_results',$args,$preview,'summary-artifact',['uid'=>'alice'],$this->native(...))['references']['job_id'];
        $step=$this->engine->step('alice',$id,$this->native(...));self::assertSame(100,$step['done']);
        $payload=$this->jobs[$id]['payload'];self::assertArrayNotHasKey('summary',$payload);
        self::assertArrayHasKey('summary_artifact',$payload);self::assertGreaterThan(0,$step['bytes']);
        self::assertStringContainsString('answer 100',$this->parts[$id.$payload['summary_artifact']['key']]);
    }
    public function testObsoleteCacheCleanupCanContinueWithoutReadingOrRepeatingAPage():void {
        $id=$this->start()['references']['job_id'];$this->garbageReady=false;
        $limited=$this->engine->step('alice',$id,$this->native(...));
        self::assertSame(0,$limited['done']);self::assertFalse($limited['complete']);self::assertTrue($limited['step_budget_exhausted']);self::assertSame([],$this->parts);
        $this->garbageReady=true;self::assertSame(100,$this->engine->step('alice',$id,$this->native(...))['done']);
    }
    public function testCompletedCheckpointIsDurableBeforeCacheSealing():void {
        $this->source=array_slice($this->source,0,1);$id=$this->start()['references']['job_id'];$this->requireCheckpointBeforeSeal=true;
        self::assertTrue($this->engine->step('alice',$id,$this->native(...))['complete']);
    }
    public function testRetainedJobFromAnotherNativeBuildRequiresNewApproval():void {
        $id=$this->start()['references']['job_id'];$this->jobs[$id]['payload']['native_runtime']=str_repeat('f',64);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->engine->status('alice',$id);
    }
    public function testSourceChangesNeverProduceMixedRevisionExport():void {
        $first=$this->start();$job=$first['references']['job_id'];$this->engine->step('alice',$job,$this->native(...));$this->revision++;
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$this->engine->step('alice',$job,$this->native(...));
    }
    public function testWorkerReentryAndDuplicateStartNeverDuplicateRows():void {
        $one=$this->start();$two=$this->start();self::assertSame($one,$two);self::assertCount(1,$this->jobs);
        $id=$one['references']['job_id'];$this->engine->step('alice',$id,$this->native(...));$this->engine->step('alice',$id,$this->native(...));$this->engine->step('alice',$id,$this->native(...));
        $again=$this->engine->step('alice',$id,$this->native(...));self::assertSame(205,$again['done']);
    }
    public function testNumericSummaryRetainsExactValuesWhenFloatingPointStatisticsOverflow():void {
        foreach([[str_repeat('9',4096)],['1e308','1e308']] as $values) {
            $native=static function(string $action,array $args)use($values):array {
                if($action==='snapshot')return ['revision'=>'4','form'=>['id'=>4,'ownerId'=>'alice']];
                if($action==='forms_results_metadata')return ['form'=>['id'=>4,'ownerId'=>'alice'],'questions'=>[['id'=>1,'text'=>'Numeric grid','type'=>'grid','extraSettings'=>['questionType'=>'number'],'options'=>[['id'=>11,'text'=>'Row','optionType'=>'row'],['id'=>12,'text'=>'Column','optionType'=>'column']]]],'submissionCount'=>count($values)];
                return ['submissions'=>array_map(static fn(string $value,int $i):array=>['id'=>$i+1,'timestamp'=>1000,'answers'=>[['questionId'=>1,'text'=>json_encode([11=>[12=>$value]],JSON_THROW_ON_ERROR)]]],$values,array_keys($values)),'hasMore'=>false,'nextId'=>count($values)];
            };
            $args=['id'=>4];$preview=$this->engine->preview('alice','forms_summarize_results',$args,['revision'=>'4'],$native);
            $id=$this->engine->start('alice','forms_summarize_results',$args,$preview,'overflow-'.count($values),['uid'=>'alice'],$native)['references']['job_id'];
            self::assertTrue($this->engine->step('alice',$id,$native)['complete']);
            $result=$this->engine->result('alice',$id,0,50);
            self::assertSame($values[0],$result['results'][0]['value']);
            self::assertSame(['count'=>count($values),'sum'=>null,'min'=>null,'max'=>null,'status'=>'out_of_range'],$result['results'][0]['numeric']);
            self::assertIsString(json_encode($result,JSON_THROW_ON_ERROR));
        }
    }
    public function testPageSummaryNeverClaimsWholeFormTotals():void {
        $args=['id'=>4];$p=$this->engine->preview('alice','forms_summarize_results',$args,['revision'=>'4','form'=>['id'=>4,'ownerId'=>'alice']],$this->native(...));
        $r=$this->engine->start('alice','forms_summarize_results',$args,$p,'summary',['uid'=>'alice','connection'=>12],$this->native(...));$id=$r['references']['job_id'];
        $this->engine->step('alice',$id,$this->native(...));self::assertFalse($this->engine->status('alice',$id)['complete']);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$this->engine->result('alice',$id,0,50);
    }
    public function testRetainedReferencesRequireCurrentFilesVisibilityBeforeContent():void {
        $args=['id'=>4,'format'=>'csv','include_files'=>true];$snapshot=$this->engine->preview('alice','forms_download_results',$args,['revision'=>'4','form'=>['id'=>4,'ownerId'=>'alice']],$this->native(...));
        $r=$this->engine->start('alice','forms_download_results',$args,$snapshot,'files-job',['uid'=>'alice','connection'=>12],$this->native(...));$id=$r['references']['job_id'];
        for($i=0;$i<3;$i++)$this->engine->step('alice',$id,$this->native(...),static fn():array=>[90=>['name'=>'retained','url'=>'https://cloud.test/f/90']]);
        self::assertTrue($this->engine->status('alice',$id)['complete']);$this->filesAllowed=false;
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$this->engine->content('alice',$id);
    }
    public function testLostStepAcknowledgementIsRecoveredWithoutCollectingAgain():void {
        $id=$this->start()['references']['job_id'];$version=$this->jobs[$id]['version'];
        $this->engine->step('alice',$id,$this->native(...),null,'step-one',$version);
        $receipt=$this->engine->recover('alice',$id,'step-one',$version);
        self::assertSame('pending',$receipt['state']);self::assertSame(100,$this->engine->status('alice',$id)['done']);
        self::assertCount(1,$this->jobs[$id]['payload']['parts']);
        $this->expectException(\OCA\Mcp\Modules\Forms\Execution\FormsBeforeEffect::class);
        $this->engine->step('alice',$id,$this->native(...),null,'step-one',$version);
    }
    public function testStaleReadClaimCanBeInvalidatedWithoutRepeatingItsPage():void {
        $id=$this->start()['references']['job_id'];$original=$this->jobs[$id];
        $this->jobs[$id]['version']++;$this->jobs[$id]['state']='working';
        $this->jobs[$id]['payload']['current_step']=['key'=>'crashed-worker','approved_version'=>$original['version']];
        self::assertNull($this->engine->recover('alice',$id,'crashed-worker',$original['version']));
        $this->now+=61;
        self::assertSame('failed_before_effect',$this->engine->recover('alice',$id,'crashed-worker',$original['version'])['state']);
        self::assertSame('collecting',$this->jobs[$id]['state']);self::assertSame(0,$this->jobs[$id]['payload']['done']);
        self::assertSame([],$this->jobs[$id]['payload']['parts']);
        $resumed=$this->engine->step('alice',$id,$this->native(...),null,'fresh-step',$this->jobs[$id]['version']);
        self::assertSame(100,$resumed['done']);
    }
    public function testPreparedVersionAdvancedBeforeJournalReservationCanBeReleased():void {
        $id=$this->start()['references']['job_id'];$prepared=$this->jobs[$id]['version'];
        $this->engine->step('alice',$id,$this->native(...),null,'concurrent-step',$prepared);
        self::assertSame('failed_before_effect',$this->engine->recover('alice',$id,'delayed-reservation',$prepared)['state']);
        self::assertSame(100,$this->engine->status('alice',$id)['done']);
        $this->expectException(\OCA\Mcp\Modules\Forms\Execution\FormsBeforeEffect::class);
        $this->engine->step('alice',$id,$this->native(...),null,'delayed-reservation',$prepared);
    }
    public function testDownloadLostReplyRetainsEvidenceWithoutRetainingOrReissuingSecret():void {
        $id=$this->start()['references']['job_id'];
        for($i=0;$i<3;$i++)$this->engine->step('alice',$id,$this->native(...));
        $version=$this->jobs[$id]['version'];$calls=0;
        $this->engine->issueDownload('alice',$id,'cap-step',$version,function()use(&$calls):array{$calls++;return ['download_url'=>'https://cloud.test/private-secret'];});
        self::assertSame('succeeded',$this->engine->recover('alice',$id,'cap-step',$version)['state']);
        self::assertSame(1,$calls);self::assertStringNotContainsString('private-secret',json_encode($this->jobs));
        self::assertSame('ready',$this->engine->status('alice',$id)['state']);
    }
    public function testRecoveryInvalidatesDelayedUnstartedCallback():void {
        $id=$this->start()['references']['job_id'];$version=$this->jobs[$id]['version'];
        self::assertSame('failed_before_effect',$this->engine->recover('alice',$id,'unstarted',$version)['state']);
        self::assertSame(0,$this->engine->status('alice',$id)['done']);
        $this->expectException(\OCA\Mcp\Modules\Forms\Execution\FormsBeforeEffect::class);
        $this->engine->step('alice',$id,$this->native(...),null,'unstarted',$version);
    }
    public function testUnknownFilesDeliveryIsRecoveredWithoutRepeatingWrite():void {
        $id=$this->start()['references']['job_id'];
        for($i=0;$i<3;$i++)$this->engine->step('alice',$id,$this->native(...));
        $version=$this->jobs[$id]['version'];$writes=0;
        try{$this->engine->deliver('alice',$id,$this->native(...),function()use(&$writes):array{$writes++;throw new \RuntimeException('Lost Files acknowledgement');},'file-step',$version);}catch(\RuntimeException){}
        self::assertSame('partial',$this->engine->recover('alice',$id,'file-step',$version)['state']);
        self::assertSame(1,$writes);self::assertSame('delivery_uncertain',$this->engine->status('alice',$id)['state']);
    }
    public function testRevokedOriginCannotReadSpooledResult():void {
        $first=$this->start();$this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$this->engine->status('bob',$first['references']['job_id']);
    }
}
