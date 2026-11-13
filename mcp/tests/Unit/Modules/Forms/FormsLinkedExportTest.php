<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedExport;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedExportAuthority;
use OCA\Mcp\Modules\Forms\Exports\FormsExportJobs;
use OCA\Mcp\Modules\Forms\Exports\FormsExportRenderer;
use OCA\Mcp\Modules\Forms\Exports\FormsJobStore;
use OCA\Mcp\Modules\Forms\Exports\FormsSpool;
use OCA\Mcp\Modules\Forms\Integration\FormsGuardIdentity;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;

final class FormsLinkedExportTest extends TestCase {
    private array $marker;private array $jobs=[];private array $parts=[];private array $rows=[];private int $writes=0;
    private bool $allowed=true;private bool $changedDestination=false;private bool $lostWrite=false;
    private FormsLinkedExport $engine;private string $source='1';
    private function metadata():array {return ['form'=>['id'=>4,'ownerId'=>'alice','title'=>'Results','isAnonymous'=>false],
        'questions'=>[['id'=>1,'type'=>'short','text'=>'Question']],'submissionCount'=>count($this->rows),'timeZone'=>'UTC'];}
    private function native(string $action,array $args):array {
        if($action==='snapshot')return ['revision'=>'8','export_revision'=>$this->source,'form'=>['id'=>4,'ownerId'=>'alice']];
        if($action==='forms_results_metadata')return $this->metadata();
        $rows=array_values(array_filter($this->rows,static fn(array $row):bool=>$row['id']>$args['after_id']));
        $more=count($rows)>$args['limit'];$rows=array_slice($rows,0,$args['limit']);
        return ['submissions'=>$rows,'hasMore'=>$more,'nextId'=>$rows===[]?$args['after_id']:end($rows)['id']];
    }
    protected function setUp():void {
        for($i=1;$i<=205;$i++)$this->rows[]=['id'=>$i,'timestamp'=>1000,'answers'=>[['questionId'=>1,'text'=>'answer '.$i]]];
        $this->marker=['association_id'=>str_repeat('a',64),'version'=>1,'state'=>'active','form_id'=>4,
            'original_owner_uid'=>'alice','current_owner_uid'=>'alice','native_runtime'=>FormsGuardIdentity::INSTALLED_MANIFEST_SHA256,
            'origin'=>['uid'=>'alice','connection'=>10,'tool'=>'forms_link_results_file','actor'=>'incarnation','references'=>['form_id'=>4,'owner_id'=>'alice']],
            'arguments'=>['id'=>4,'format'=>'csv','include_respondents'=>false,'include_files'=>false],
            'schema_digest'=>FormsLinkedExport::schemaDigest($this->metadata()),
            'dirty_generation'=>1,'last_completed_generation'=>0,'active_cycle'=>null,
            'last_verified'=>['file_id'=>77,'path'=>'/results.csv','etag'=>'old','sha256'=>hash('sha256','old'),'size'=>3]];
        $store=$this->createMock(FormsJobStore::class);
        $store->method('get')->willReturnCallback(fn(string $id):?array=>$this->jobs[$id]??null);
        $store->method('create')->willReturnCallback(function(array $row):bool{if(isset($this->jobs[$row['job_id']]))return false;$this->jobs[$row['job_id']]=$row;return true;});
        $store->method('compareAndSwap')->willReturnCallback(function(array $old,array $next):bool{if(($this->jobs[$old['job_id']]??null)!==$old)return false;$this->jobs[$old['job_id']]=$next;return true;});
        $spool=$this->createMock(FormsSpool::class);$spool->method('retain')->willReturn(true);
        $spool->method('put')->willReturnCallback(function(string $id,string $content):array{$key=hash('sha256',$content);$this->parts[$id.$key]=$content;return ['key'=>$key,'bytes'=>strlen($content)];});
        $spool->method('get')->willReturnCallback(fn(string $id,array $part):string=>$this->parts[$id.$part['key']]);
        $jobs=new FormsExportJobs($store,$spool,new FormsExportRenderer(),function(array $origin):array{if(!$this->allowed)throw new ToolFailure('revoked');return $origin;},static fn():int=>1000);
        $authority=$this->createMock(FormsLinkedExportAuthority::class);
        $authority->method('claim')->willReturnCallback(fn():?array=>$this->marker['state']==='active'?$this->marker:null);
        $authority->method('source')->willReturn($this->native(...));
        $authority->method('destination')->willReturnCallback(function():array {
            if($this->changedDestination)throw new ToolFailure('Destination changed');
            return ['path'=>'/results.csv','overwrite'=>true,'file_id'=>77,'etag'=>$this->marker['last_verified']['etag'],'plan'=>[]];
        });
        $authority->method('checkpoint')->willReturnCallback(function(array $claim,array $cycle):array {
            self::assertSame($this->marker,$claim);$this->marker['version']++;$this->marker['active_cycle']=$cycle;return $this->marker;
        });
        $authority->method('restart')->willReturnCallback(function(array $claim,array $cycle):bool {
            if($this->marker['dirty_generation']<=$cycle['generation'])return false;
            $this->marker['active_cycle']=null;return true;
        });
        $authority->method('pause')->willReturnCallback(function(array $claim,string $reason):void {$this->marker['state']=$reason==='delivery_uncertain'?'write_uncertain':'paused';});
        $authority->method('write')->willReturnCallback(function(array $claim,string $content,array $job):array {
            $this->writes++;self::assertSame('alice',$job['actor_uid']);self::assertSame(10,$job['payload']['origin']['connection']);
            if($this->lostWrite)throw new \RuntimeException('ack lost');
            $this->marker['last_completed_generation']=$claim['active_cycle']['generation'];
            $this->marker['active_cycle']=null;$this->marker['last_verified']['etag']='new-'.$this->writes;
            return ['file_id'=>77,'etag'=>$this->marker['last_verified']['etag'],'sha256'=>hash('sha256',$content),'size'=>strlen($content)];
        });
        $this->engine=new FormsLinkedExport($authority,$jobs);
    }
    public function testEachDirtyGenerationUsesFreshBoundedCycleAndOriginalAuthority():void {
        foreach([100,200,205] as $done)self::assertSame($done,$this->engine->step($this->marker['association_id'])['done']);
        self::assertSame(0,$this->writes);self::assertSame('delivered',$this->engine->step($this->marker['association_id'])['state']);
        self::assertSame(1,$this->writes);self::assertSame('idle',$this->engine->step($this->marker['association_id'])['state']);
        $this->marker['dirty_generation']=2;$this->source='2';$this->rows[]=['id'=>206,'timestamp'=>1001,'answers'=>[]];
        for($i=0;$i<4;$i++)$this->engine->step($this->marker['association_id']);
        self::assertSame(2,$this->writes);self::assertCount(2,$this->jobs);self::assertSame(2,$this->marker['last_completed_generation']);
    }
    public function testRecurringOverwriteUsesLastVerifiedTupleEvenWhenInitialLinkCreatedTheFile():void {
        $this->marker['arguments']+=['path'=>'/results.csv','overwrite'=>false];
        $this->engine->step($this->marker['association_id']);
        self::assertTrue(array_values($this->jobs)[0]['payload']['arguments']['overwrite']);
    }
    public function testChangedSourceRestartsReadOnlyCollectionInANewDirtyGeneration():void {
        self::assertSame(100,$this->engine->step($this->marker['association_id'])['done']);
        $this->source='2';$this->marker['dirty_generation']=2;$this->rows[]=['id'=>206,'timestamp'=>1001,'answers'=>[]];
        self::assertSame('pending',$this->engine->step($this->marker['association_id'])['state']);
        for($i=0;$i<4;$i++)$last=$this->engine->step($this->marker['association_id']);
        self::assertSame('delivered',$last['state']);self::assertSame(1,$this->writes);
        self::assertCount(2,$this->jobs);self::assertSame(2,$this->marker['last_completed_generation']);
    }
    public function testTransferredFormNeverRunsAsItsNewOwner():void {
        $this->marker['current_owner_uid']='bob';
        self::assertSame('paused',$this->engine->step($this->marker['association_id'])['state']);
        self::assertSame(0,$this->writes);self::assertSame([],$this->jobs);
    }
    public function testManualDestinationEditPausesBeforeStartingAnotherExport():void {
        $this->changedDestination=true;
        self::assertSame('paused',$this->engine->step($this->marker['association_id'])['state']);
        self::assertSame([],$this->jobs);self::assertSame(0,$this->writes);
    }
    public function testRevokedOriginalGrantCannotDeliverCollectedOutput():void {
        for($i=0;$i<3;$i++)$this->engine->step($this->marker['association_id']);
        $this->allowed=false;self::assertSame('paused',$this->engine->step($this->marker['association_id'])['state']);
        self::assertSame(0,$this->writes);
    }
    public function testAmbiguousDeliveryCannotBeAutomaticallyRepeated():void {
        for($i=0;$i<3;$i++)$this->engine->step($this->marker['association_id']);
        $this->lostWrite=true;self::assertSame('write_uncertain',$this->engine->step($this->marker['association_id'])['state']);
        self::assertSame('idle',$this->engine->step($this->marker['association_id'])['state']);self::assertSame(1,$this->writes);
    }
    public function testUiOrUnlinkedAssociationIsNotAdopted():void {
        $this->marker['state']='unlinked';self::assertSame('idle',$this->engine->step($this->marker['association_id'])['state']);
        self::assertSame([],$this->jobs);self::assertSame(0,$this->writes);
    }
}
