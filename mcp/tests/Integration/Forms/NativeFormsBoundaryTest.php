<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
/** Runs real native controllers in separate PHP processes/database sessions, never fixture mocks. */
final class NativeFormsBoundaryTest extends TestCase {
    private string $probe;
    protected function setUp(): void {
        $this->probe=getenv('MCP_FORMS_NATIVE_PROBE') ?: dirname(__DIR__,4).'/native/forms-guard/tests/native-probe.php';
        if (!getenv('MCP_FORMS_NATIVE_BOUNDARY')) {$this->markTestSkipped('Requires explicit disposable native Forms database cell');}
        self::assertFileExists($this->probe);
    }
    private function start(array $request): array {
        $p=proc_open([PHP_BINARY,$this->probe],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($p);fwrite($pipes[0],json_encode($request,JSON_THROW_ON_ERROR));fclose($pipes[0]);return [$p,$pipes];
    }
    private function finish(array $job): array {
        [$p,$pipes]=$job;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($p),$err);self::assertNotSame('',trim($out),'Native probe must emit an outcome: '.$err);
        return json_decode($out,true,64,JSON_THROW_ON_ERROR);
    }
    private function call(array $request): array {return $this->finish($this->start($request));}
    private function outcome(array $request): mixed {$r=$this->call($request);self::assertTrue($r['ok'],json_encode($r));return $r['result'];}
    public function testRollbackPreservesPreviousAnswerSet(): void {
        $fixture=$this->outcome(['action'=>'fixture']);$result=$this->outcome(['action'=>'rollback','args'=>$fixture]);
        self::assertTrue($result['failed'],'Injected storage failure must occur');
        self::assertSame(['old first','old second'],$result['answers'],'No committed mixed or partial answer set');
    }
    public function testConcurrentLastSubmissionSlotHasOneWinner(): void {
        $f=$this->outcome(['action'=>'fixture','args'=>['max'=>1]]);$this->outcome(['action'=>'delay']);
        try {
            $req=['action'=>'api','method'=>'newSubmission','args'=>[$f['id'],[(string)$f['q1']=>['concurrent']]]];
            $a=$this->start($req);$b=$this->start($req);$ra=$this->finish($a);$rb=$this->finish($b);
            self::assertSame(1,(int)$ra['ok']+(int)$rb['ok'],'Exactly one native request wins the final slot');
            self::assertSame(1,$this->outcome(['action'=>'count','args'=>['id'=>$f['id']]]));
        } finally {$this->outcome(['action'=>'undelay']);}
    }
    public function testNativeUiWriteInvalidatesApprovedRevision(): void {
        $f=$this->outcome(['action'=>'fixture']);$input=['id'=>$f['id'],'fields'=>['title'=>'approved title']];
        $snap=$this->outcome(['action'=>'snapshot','args'=>['action'=>'forms_update','input'=>$input]]);
        $this->outcome(['action'=>'api','method'=>'updateForm','args'=>[$f['id'],['title'=>'native changed']]]);
        $r=$this->call(['action'=>'apply','args'=>['action'=>'forms_update','input'=>$input,'revision'=>$snap['revision'],'key'=>bin2hex(random_bytes(16))]]);
        self::assertFalse($r['ok']);self::assertSame('Forms preview is stale',$r['error']);
    }
    public function testNativeShareWriterUsesSameFence(): void {
        $f=$this->outcome(['action'=>'fixture']);$input=['id'=>$f['id'],'fields'=>['title'=>'approved title']];
        $snap=$this->outcome(['action'=>'snapshot','args'=>['action'=>'forms_update','input'=>$input]]);
        $this->outcome(['action'=>'api','share'=>true,'method'=>'newShare','args'=>[$f['id'],3,'',['submit']]]);
        $r=$this->call(['action'=>'apply','args'=>['action'=>'forms_update','input'=>$input,'revision'=>$snap['revision'],'key'=>bin2hex(random_bytes(16))]]);
        self::assertFalse($r['ok']);self::assertSame('Forms preview is stale',$r['error']);
    }
    public function testLostCommitAcknowledgementCannotBeReplayed(): void {
        $input=['title'=>'Durable operation '.bin2hex(random_bytes(4))];
        $snap=$this->outcome(['action'=>'snapshot','args'=>['action'=>'forms_create','input'=>$input]]);$key=bin2hex(random_bytes(16));
        $request=['action'=>'apply','args'=>['action'=>'forms_create','input'=>$input,'revision'=>$snap['revision'],'key'=>$key]];
        $first=$this->outcome($request); // Caller loses this acknowledgement; only the key survives.
        $receipt=$this->outcome(['action'=>'lookup','args'=>['key'=>$key]]);$again=$this->outcome($request);
        self::assertSame($first['references'],$receipt['references']);self::assertSame($first['references'],$again['references']);
        self::assertSame('committed',$again['phases']['database']);
        $request['args']['input']['title']='different intent';$conflict=$this->call($request);
        self::assertFalse($conflict['ok']);self::assertSame('Operation key conflict',$conflict['error']);
    }

    public function testUnknownSourceAndForeignActorsFailClosed(): void {
        self::assertTrue($this->outcome(['action'=>'capabilities'])['qualified']);
        self::assertFalse($this->outcome(['action'=>'source_drift']));
        self::assertTrue($this->outcome(['action'=>'capabilities'])['qualified']);
        $foreign=$this->call(['action'=>'foreign_actor']);self::assertFalse($foreign['ok']);
        self::assertSame('Forms actor is unavailable',$foreign['error']);
    }
    public function testReceiptContainsNoAnswersAndEventsDoNotReplay(): void {
        $f=$this->outcome(['action'=>'fixture']);$canary='private-answer-'.bin2hex(random_bytes(8));
        $input=['id'=>$f['id'],'answers'=>[(string)$f['q1']=>[$canary]]];
        $snapshot=$this->outcome(['action'=>'snapshot','args'=>['action'=>'forms_submit_response','input'=>$input]]);
        $key=bin2hex(random_bytes(16));$req=['action'=>'apply','args'=>['action'=>'forms_submit_response','input'=>$input,'revision'=>$snapshot['revision'],'key'=>$key]];
        $first=$this->outcome($req);$again=$this->outcome($req);
        self::assertSame($first['references'],$again['references']);
        self::assertSame(1,$this->outcome(['action'=>'count','args'=>['id'=>$f['id']]]));
        self::assertCount(1,$again['phases']['events']);
        self::assertSame('attempted_unverified',$again['phases']['events'][0]['phase']);
        self::assertSame('partial',$again['state'],'Native swallowed Activity errors must not be represented as verified delivery');
        $receipt=$this->outcome(['action'=>'receipt','args'=>['key'=>$key]]);
        self::assertStringNotContainsString($canary,json_encode($receipt));
    }
}
