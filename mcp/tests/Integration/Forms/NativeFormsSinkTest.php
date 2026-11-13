<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class NativeFormsSinkTest extends TestCase {
 protected function setUp():void {if(!getenv('MCP_FORMS_NATIVE_BOUNDARY'))$this->markTestSkipped('Requires disposable native cell');}
 private function runCase(string $case):array {$p=proc_open([PHP_BINARY,'/tmp/sink-probe.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode(compact('case')));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$err);$r=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($r['ok'],json_encode($r));return $r;}
 public function testStringAndStreamCallbacksHoldActualExclusiveLock():void {foreach(['positive','positive_stream'] as $case){$r=$this->runCase($case);self::assertFalse($r['failed'],json_encode($r));self::assertTrue($r['exclusive']);self::assertSame(2,$r['calls']);self::assertSame('written',$r['bytes']);}}
 public function testCommittedScopeAndGrantRevocationAfterLockPreservesBytes():void {foreach(['scope','scope_stream','grant','grant_stream'] as $case){$r=$this->runCase($case);self::assertTrue($r['revoked'],json_encode($r));self::assertTrue($r['failed']);self::assertFalse($r['before_effect']);self::assertSame('original',$r['bytes']);}}
 public function testReplacedIdentityAndUnknownStorageAreRefused():void {foreach(['identity','unknown'] as $case){$r=$this->runCase($case);self::assertTrue($r['failed']);self::assertSame($case==='identity'?'replacement':'original',$r['bytes']);self::assertSame($case==='identity'?1:0,$r['calls']);}}
 public function testRefusalAfterEmptyFileCreationNeverClaimsBeforeEffect():void {$r=$this->runCase('grant_before');self::assertTrue($r['failed']);self::assertFalse($r['before_effect']);self::assertSame('',$r['bytes']);}

 private function attachment(string $action,array $args=[]):mixed {
  $p=proc_open([PHP_BINARY,'/tmp/attachment-probe.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode(compact('action','args')));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$err);$r=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($r['ok'],json_encode($r));return $r['result'];
 }
 public function testTwoManagedFilesDeleteAndRestoreWithVerifiedRecovery():void {
  $f=$this->attachment('fixture');$handles=[];foreach(['first bytes','second bytes'] as $content){$handles[]=['mcpHandle'=>$this->attachment('stage',$f+compact('content'))['data']['files'][0]['handle']];}
  $write=function($action,$input){$s=$this->attachment('snapshot',compact('action','input'));return $this->attachment('apply',['action'=>$action,'input'=>$input,'revision'=>$s['revision'],'manifest'=>$s['file_manifest'],'key'=>bin2hex(random_bytes(16))]);};
  $submitted=$write('forms_submit_response',['id'=>$f['id'],'answers'=>[$f['question_id']=>$handles]]);$refs=$this->attachment('references',['id'=>$f['id'],'submission_id'=>$submitted['references']['submission_id']])['files'];self::assertCount(2,$refs);
  $deleted=$write('forms_delete_form',['id'=>$f['id']]);foreach($refs as $ref){self::assertNull($this->attachment('hash',['file_id'=>$ref['file_id']]));}
  $restored=$write('forms_restore',['backup_id'=>$deleted['references']['backup_id']]);$actual=[];foreach($restored['data']['recovered_file_ids'] as $id){$actual[]=$this->attachment('hash',['file_id'=>$id]);}$expected=[hash('sha256','first bytes'),hash('sha256','second bytes')];sort($actual);sort($expected);self::assertSame($expected,$actual);
 }
 public function testEmptyAllocationUsesSamePostLockBoundary():void {$ok=$this->runCase('allocation');self::assertFalse($ok['failed'],json_encode($ok));self::assertTrue($ok['exclusive']);self::assertTrue($ok['allocation_exists']);$denied=$this->runCase('scope_allocation');self::assertTrue($denied['revoked']);self::assertTrue($denied['failed']);self::assertFalse($denied['allocation_exists']);}
 public function testNoopPreflightAuthorizesWithoutAllocatingOrChangingBytes():void {foreach(['noop','noop_allocation'] as $case){$r=$this->runCase($case);self::assertFalse($r['failed'],json_encode($r));self::assertSame(1,$r['calls']);self::assertSame('original',$r['bytes']);self::assertSame($case==='noop',$r['allocation_exists']);}}
 public function testCompetingNativeWriteAfterAdmissionPreservesNewContent():void {$r=$this->runCase('etag');self::assertTrue($r['failed'],json_encode($r));self::assertSame('competing',$r['bytes']);self::assertFalse($r['before_effect']);}
 public function testApprovedDigestRefusesNativeEtagReuse():void {$r=$this->runCase('digest');self::assertSame($r['etags']['node'],$r['etags']['cache'],json_encode($r));self::assertTrue($r['failed'],json_encode($r));self::assertSame('changed!',$r['bytes']);self::assertFalse($r['before_effect']);}
}
