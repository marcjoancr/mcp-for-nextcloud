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
    private array $jobs=[];private array $parts=[];private int $revision=4;private array $source=[];private FormsExportJobs $engine;
    protected function setUp():void {
        $store=$this->createMock(FormsJobStore::class);
        $store->method('get')->willReturnCallback(fn(string $id):?array=>$this->jobs[$id]??null);
        $store->method('create')->willReturnCallback(function(array $job):bool {if(isset($this->jobs[$job['job_id']]))return false;$this->jobs[$job['job_id']]=$job;return true;});
        $store->method('compareAndSwap')->willReturnCallback(function(array $old,array $next):bool {if(($this->jobs[$old['job_id']]??null)!==$old)return false;$this->jobs[$old['job_id']]=$next;return true;});
        $spool=$this->createMock(FormsSpool::class);
        $spool->method('put')->willReturnCallback(function(string $job,string $content):array {$key=hash('sha256',$content);$this->parts[$job.$key]=$content;return ['key'=>$key,'bytes'=>strlen($content)];});
        $spool->method('get')->willReturnCallback(fn(string $job,array $part):string=>$this->parts[$job.$part['key']]);
        $this->engine=new FormsExportJobs($store,$spool,new FormsExportRenderer(),static fn(array $o):array=>$o,static fn():int=>1000);
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
    public function testFullExportIncludesEverySnapshotRowOnce():void {
        $first=$this->start();self::assertSame('pending',$first['state']);$job=$first['references']['job_id'];
        $a=$this->engine->step('alice',$job,$this->native(...));self::assertSame(100,$a['done']);self::assertFalse($a['complete']);
        $b=$this->engine->step('alice',$job,$this->native(...));self::assertSame(200,$b['done']);
        $c=$this->engine->step('alice',$job,$this->native(...));self::assertSame(205,$c['done']);self::assertTrue($c['complete']);
        $content=$this->engine->content('alice',$job);
        $f=fopen('php://memory','w+');fwrite($f,$content);rewind($f);fgetcsv($f,0,',','"','');fgetcsv($f,0,',','"','');$ids=[];while(($r=fgetcsv($f,0,',','"',''))!==false)$ids[]=(int)$r[0];fclose($f);
        self::assertSame(range(1,205),$ids);
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
    public function testPageSummaryNeverClaimsWholeFormTotals():void {
        $args=['id'=>4];$p=$this->engine->preview('alice','forms_summarize_results',$args,['revision'=>'4','form'=>['id'=>4,'ownerId'=>'alice']],$this->native(...));
        $r=$this->engine->start('alice','forms_summarize_results',$args,$p,'summary',['uid'=>'alice','connection'=>12],$this->native(...));$id=$r['references']['job_id'];
        $this->engine->step('alice',$id,$this->native(...));self::assertFalse($this->engine->status('alice',$id)['complete']);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$this->engine->result('alice',$id,0,50);
    }
    public function testRevokedOriginCannotReadSpooledResult():void {
        $first=$this->start();$this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$this->engine->status('bob',$first['references']['job_id']);
    }
}
