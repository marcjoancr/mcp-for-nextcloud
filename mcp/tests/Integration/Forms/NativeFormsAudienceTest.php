<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class NativeFormsAudienceTest extends TestCase {
 protected function setUp():void {if(!getenv('MCP_FORMS_NATIVE_BOUNDARY'))$this->markTestSkipped('Requires disposable native cell');}
 private function runCase(array $request):array {$p=proc_open([PHP_BINARY,'/tmp/audience-probe.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode($request));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$err);$r=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($r['ok'],json_encode($r));return $r['result'];}
 public function testGroupDigestAndDeliveryShareOneFreshAudience():void {self::assertSame(['digest_changed'=>true,'frozen_count'=>1,'fresh_count'=>2,'global_count'=>1,'same_delivery_graph'=>true],$this->runCase(['case'=>'audience']));}
 public function testExistingCircleReadAndEditUseAuthenticatedNativeSession():void {$fixture=$this->runCase(['case'=>'circle_fixture']);$result=$this->runCase(['case'=>'circle_read']+$fixture);self::assertTrue($result['native_edit']);self::assertTrue($result['native_results']);self::assertSame($fixture['id'],$result['read_id']);self::assertSame($fixture['submission_id'],$result['response_id']);self::assertTrue($result['updated']);}
 public function testDeferredMetadataRetainsIsolatedActorGraph():void {self::assertSame(['same_form'=>true,'session'=>null],$this->runCase(['case'=>'deferred_metadata']));}
}
