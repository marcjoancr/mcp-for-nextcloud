<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class NativeFormsOriginTest extends TestCase {
 protected function setUp():void {if(!getenv('MCP_FORMS_NATIVE_BOUNDARY')){$this->markTestSkipped('Requires disposable native cell');}}
 private function call(string $action,array $args=[],?string $actor=null,?string $probe=null):mixed {
   $p=proc_open([PHP_BINARY,$probe??(getenv('MCP_FORMS_ORIGIN_PROBE')?:'/tmp/origin-probe.php')],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($p);
   fwrite($pipes[0],json_encode(compact('action','args','actor')));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$err);$r=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($r['ok'],json_encode($r));return $r['result'];
 }
 private function fixture():array {$f=$this->call('fixture');self::assertCount(1,$f['effects']);self::assertSame('pending',$f['effects'][0]['phase']);return $f;}
 private function drain(array $f):array {return $this->call('drain',['key'=>$f['key'],'effect_key'=>$f['effects'][0]['effect_key']]);}
 private function mail(array $f):string {$rows=$this->drain($f);self::assertCount(2,$rows,json_encode($rows));self::assertSame('confirmation_mail',$rows[0]['kind']);self::assertSame('pending',$rows[0]['phase']);return $rows[0]['payload_ref'];}
 public function testRevokedOriginalToolCannotDispatchPendingNativeEvent():void {
   $f=$this->fixture();$this->call('gate',['allow'=>false]);try{$rows=$this->drain($f);self::assertCount(1,$rows);self::assertSame('uncertain',$rows[0]['phase']);}finally{$this->call('gate',['allow'=>true]);}
 }
 public function testChangedNativeDataCannotDispatchFrozenEvent():void {$f=$this->fixture();$this->call('change',$f);$rows=$this->drain($f);self::assertCount(1,$rows);self::assertSame('uncertain',$rows[0]['phase']);}
 public function testMailJobHasOpaquePayloadAndSendsOnceUnderOriginalActor():void {
   $f=$this->fixture();$ref=$this->mail($f);$jobs=$this->call('jobs',compact('ref'));self::assertCount(1,$jobs);self::assertSame(['_mcp_mail'=>$ref],json_decode($jobs[0]['argument'],true));
   $sent=$this->call('mail',compact('ref'));self::assertSame(1,$sent['sent']);self::assertNull($sent['session']);self::assertSame('attempted_unverified',$sent['phase']);self::assertSame(0,$this->call('mail',compact('ref'))['sent']);
 }
 public function testMailWorkerRechecksRevocationAfterQueueing():void {
   $f=$this->fixture();$ref=$this->mail($f);$this->call('gate',['allow'=>false]);try{$r=$this->call('mail',compact('ref'));self::assertSame(0,$r['sent']);self::assertSame('uncertain',$r['phase']);}finally{$this->call('gate',['allow'=>true]);}
 }
 public function testMailWorkerRefusesChangedPayloadDependencies():void {$f=$this->fixture();$ref=$this->mail($f);$this->call('change',$f);$r=$this->call('mail',compact('ref'));self::assertSame(0,$r['sent']);self::assertSame('uncertain',$r['phase']);}
 public function testDeferredReadUsesOriginalActorWithoutMutatingCronSession():void {$f=$this->fixture();$r=$this->call('deferred',$f);self::assertSame(1,$r['count']);self::assertNull($r['session']);self::assertNotSame('',$r['revision']);}

 public function testNumericGridRecoveryRemapsIdentitiesAndPreservesZeroAndValues():void {
   $r=$this->call('grid');self::assertSame('restored_verified',$r['phase']);$row=null;$columns=[];
   foreach($r['options'] as $o){if($o['optionType']==='row'){$row=$o['id'];}else{$columns[$o['text']]=$o['id'];}}
   self::assertSame([$row=>[$columns['Zero']=>0,$columns['Number']=>$r['original_value']]],$r['answer']);
 }
 public function testRecreatedActorCannotDiscoverRetainedBackupOrConsumeOldHandle():void {
   $actor='origin-'.bin2hex(random_bytes(5));$probe='/tmp/attachment-probe.php';$this->call('create_user',['uid'=>$actor],null,$probe);
   $f=$this->call('fixture',[],$actor,$probe);$handle=$this->call('stage',$f,$actor,$probe)['data']['files'][0]['handle'];
   $other=$this->call('fixture',[],$actor,$probe);$action='forms_delete_form';$input=['id'=>$other['id']];$snap=$this->call('snapshot',compact('action','input'),$actor,$probe);
   $deleted=$this->call('apply',['action'=>$action,'input'=>$input,'revision'=>$snap['revision'],'manifest'=>$snap['file_manifest'],'key'=>bin2hex(random_bytes(16))],$actor,$probe);
   $this->call('rotate',[],$actor);
   // Use fresh signed consent from the newly enrolled incarnation; it still must not discover the old artifacts.
   foreach([['forms_restore',['backup_id'=>$deleted['references']['backup_id']]],['forms_submit_response',['id'=>$f['id'],'answers'=>[$f['question_id']=>[['mcpHandle'=>$handle]]]]]] as [$action,$input]) {
     $p=proc_open([PHP_BINARY,$probe],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode(['action'=>'snapshot','actor'=>$actor,'args'=>compact('action','input')]));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$err);$r=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertFalse($r['ok']);self::assertSame($action==='forms_restore'?'Recovery is unavailable':'File handle is unavailable',$r['error']);
   }
 }

 public function testNativeValidationAfterFileEffectIsNotReportedAsBeforeEffect():void {
   $r=$this->call('file_fault');self::assertSame('OCP\AppFramework\OCS\OCSBadRequestException',$r['class']);self::assertTrue($r['bytes_exist']);self::assertSame(0,$r['operation_rows']);
 }

 public function testRevocationWhileWaitingForNativeFencePreventsMutation():void {
   $native='/tmp/native-probe.php';$f=$this->call('fixture',[],null,$native);$input=['id'=>$f['id'],'answers'=>[$f['q1']=>['must never commit']]];$action='forms_submit_response';$snap=$this->call('snapshot',compact('action','input'),null,$native);
   $holder=proc_open([PHP_BINARY,'/tmp/fence-barrier.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$hold);self::assertSame("LOCKED\n",fgets($hold[1]));
   $waiter=proc_open([PHP_BINARY,$native],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$wait);
   fwrite($wait[0],json_encode(['action'=>'apply_wait','args'=>['action'=>$action,'input'=>$input,'revision'=>$snap['revision'],'key'=>bin2hex(random_bytes(16))]]));fclose($wait[0]);
   try {
     self::assertSame("WAITING\n",fgets($wait[1]));$this->call('gate',['allow'=>false]);fwrite($hold[0],"RELEASE\n");fclose($hold[0]);
     $r=json_decode(stream_get_contents($wait[1]),true,64,JSON_THROW_ON_ERROR);$err=stream_get_contents($wait[2]);fclose($wait[1]);fclose($wait[2]);self::assertSame(0,proc_close($waiter),$err);
     self::assertFalse($r['ok'],'Revocation during fence wait must prevent the native mutation');self::assertSame('OCA\\Forms\\Mcp\\BeforeEffect',$r['class']);self::assertSame(0,$this->call('count',$f,null,$native));
   } finally {if(is_resource($hold[0])){fwrite($hold[0],"RELEASE\n");fclose($hold[0]);}fclose($hold[1]);fclose($hold[2]);proc_close($holder);$this->call('gate',['allow'=>true]);}
 }
 public function testPublicShareHasNoFilesManifestDespiteLargeResponseHistory():void {self::assertSame([[],[]],$this->call('public_share'));}
 public function testRestoreHonorsCurrentNativeCreationRestriction():void {self::assertSame(['denied'=>true,'unchanged'=>true,'retained'=>true],$this->call('restore_restricted'));}

 public function testAuthorityRefreshInsideExistingTransactionSeesCommittedRevocation():void {
   $f=$this->call('fixture',[],null,'/tmp/native-probe.php');$p=proc_open([PHP_BINARY,'/tmp/authority-barrier.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode(['id'=>$f['id']])."\n");
   try {self::assertSame("CHECKED\n",fgets($pipes[1]));$this->call('gate',['allow'=>false]);fwrite($pipes[0],"RECHECK\n");fclose($pipes[0]);$r=json_decode(stream_get_contents($pipes[1]),true,64,JSON_THROW_ON_ERROR);self::assertTrue($r['refused']);}
   finally {if(is_resource($pipes[0])){fwrite($pipes[0],"RECHECK\n");fclose($pipes[0]);}fclose($pipes[1]);fclose($pipes[2]);proc_close($p);$this->call('gate',['allow'=>true]);}
 }
}
