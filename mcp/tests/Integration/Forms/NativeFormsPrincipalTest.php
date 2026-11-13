<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class NativeFormsPrincipalTest extends TestCase {
 protected function setUp():void {if(!getenv('MCP_FORMS_NATIVE_BOUNDARY')){$this->markTestSkipped('Requires disposable native cell');}}
 private function runCase(string $case):array {
  $process=proc_open([PHP_BINARY,'/tmp/principal-probe.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  fwrite($pipes[0],json_encode(compact('case')));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$err);
  $result=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($result['ok'],json_encode($result));return $result['result'];
 }
 public function testWarmedPrincipalCachesObserveIndependentRevocations():void {foreach(['enabled','forms','mcp','admin'] as $case){$r=$this->runCase($case);self::assertTrue($r['refused'],$case.' revocation must be current');self::assertTrue($r['original_cache_unchanged']);}}
 public function testFreshNativeConfigPreservesTypedDefaultsAndCreationRestriction():void {
  $result=$this->runCase('config');self::assertSame(['allowPermitAll'=>true,'allowPublicLink'=>true,'allowCustomPublicShareTokens'=>false,'allowShowToAll'=>true,'creationAllowedGroups'=>[],'restrictCreation'=>false,'allowConfirmationEmail'=>false,'confirmationEmailRateLimit'=>3,'allowComments'=>false],$result['defaults']);self::assertTrue($result['restricted']);
 }
 public function testPrincipalCheckDoesNotBorrowOrMutateWorkerSession():void {self::assertNull($this->runCase('session')['session']);}
 public function testNativeGroupShareUsesFreshMembershipWithoutGlobalMutation():void {self::assertSame(['had_results'=>true,'revoked'=>true,'global_still_warm'=>true],$this->runCase('group'));}
 public function testGroupRestrictedAppUsesFreshMembership():void {self::assertTrue($this->runCase('app_group')['refused']);}
 public function testUnknownUserBackendRefusesQualification():void {self::assertTrue($this->runCase('unknown_backend')['refused']);}
 public function testFreshAdminGrantDoesNotReuseCachedNegativeRole():void {self::assertTrue($this->runCase('admin_granted')['original_cache_unchanged']);}
 public function testUnknownGroupBackendRefusesQualification():void {self::assertTrue($this->runCase('unknown_group_backend')['refused']);}
 public function testNativeControllerValidatorUsesCurrentConfigAfterPreview():void {self::assertSame(['refused'=>true,'token_preserved'=>true],$this->runCase('validator'));}
 public function testConfiguredReplicasRefusePrincipalQualification():void {self::assertSame(['qualified'=>false,'refused'=>true],$this->runCase('replica'));}

 private function probe(string $path,array $request,?string $uid=null):array {
  $environment=$uid===null?null:array_merge(getenv(),['MCP_FORMS_NATIVE_OWNER'=>$uid]);
  $process=proc_open([PHP_BINARY,$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment);
  fwrite($pipes[0],json_encode($request));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$err);
  return json_decode($out,true,64,JSON_THROW_ON_ERROR);
 }
 private function setEnabled(string $uid,bool $value):void {
  $process=proc_open([PHP_BINARY,'/tmp/principal-writer.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  fwrite($pipes[0],json_encode(['uid'=>$uid,'action'=>'enabled','value'=>$value]));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$err);self::assertSame("committed\n",$out);
 }
 public function testDisableWhileWaitingForNativeFencePreventsMutation():void {
  $uid='principal-wait-'.bin2hex(random_bytes(5));$created=$this->probe('/tmp/attachment-probe.php',['action'=>'create_user','args'=>compact('uid')]);self::assertTrue($created['ok']);
  $fixture=$this->probe('/tmp/native-probe.php',['action'=>'fixture'],$uid);self::assertTrue($fixture['ok'],json_encode($fixture));$f=$fixture['result'];
  $input=['id'=>$f['id'],'answers'=>[$f['q1']=>['must not commit']]];$action='forms_submit_response';$snap=$this->probe('/tmp/native-probe.php',['action'=>'snapshot','args'=>compact('action','input')],$uid);self::assertTrue($snap['ok'],json_encode($snap));
  $holder=proc_open([PHP_BINARY,'/tmp/fence-barrier.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$hold);self::assertSame("LOCKED\n",fgets($hold[1]));
  $waiter=proc_open([PHP_BINARY,'/tmp/native-probe.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$wait,null,array_merge(getenv(),['MCP_FORMS_NATIVE_OWNER'=>$uid]));
  fwrite($wait[0],json_encode(['action'=>'apply_wait','args'=>['action'=>$action,'input'=>$input,'revision'=>$snap['result']['revision'],'key'=>bin2hex(random_bytes(16))]]));fclose($wait[0]);
  try {
   self::assertSame("WAITING\n",fgets($wait[1]));$this->setEnabled($uid,false);fwrite($hold[0],"RELEASE\n");fclose($hold[0]);
   $result=json_decode(stream_get_contents($wait[1]),true,64,JSON_THROW_ON_ERROR);$err=stream_get_contents($wait[2]);fclose($wait[1]);fclose($wait[2]);self::assertSame(0,proc_close($waiter),$err);
   self::assertFalse($result['ok']);self::assertSame('OCA\\Forms\\Mcp\\BeforeEffect',$result['class']);$count=$this->probe('/tmp/native-probe.php',['action'=>'count','args'=>$f],$uid);self::assertSame(0,$count['result']);
  } finally {if(is_resource($hold[0])){fwrite($hold[0],"RELEASE\n");fclose($hold[0]);}fclose($hold[1]);fclose($hold[2]);proc_close($holder);$this->setEnabled($uid,true);}
 }
}
