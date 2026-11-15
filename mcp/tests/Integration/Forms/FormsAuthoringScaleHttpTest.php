<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
/** Real disposable HTTP boundary; complete authoring and independent exact recovery over 101 responses.
 * Stage the tracked native origin policy fixture when running outside the repository;
 * MCP_FORMS_NATIVE_ORIGIN_FIXTURE names that checked prerequisite before any fixture effect. */
final class FormsAuthoringScaleHttpTest extends TestCase {
 public function testScaledAuthoringAndSharingPreserveNativeHistoryAndExactPrivateRecovery():void {
  if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')$this->markTestSkipped('Requires explicitly selected disposable Forms HTTP cell.');
  $fixture=getenv('MCP_FORMS_AUTHORING_HTTP_PROBE')?:__DIR__.'/fixtures/authoring-public-http.php';self::assertFileExists($fixture);
  $p=proc_open([PHP_BINARY,$fixture],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($p);
  fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  self::assertSame(0,proc_close($p),$out."\n".$err);$r=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($r['ok']);
  foreach(['forms_update_complete_scoped_metadata','forms_update_share_complete_scoped_metadata',
   'forms_update_share_partial_event_attempt_honest','forms_update_share_fresh_exact_receipt',
   'forms_unshare_complete_scoped_metadata','forms_unshare_partial_event_attempt_honest','forms_unshare_fresh_exact_receipt',
   'question_native_history_preserved','independent_recovery_oracle_rejects_wrong_bindings','sealed_exact_private_recovery']as$key)self::assertTrue($r['checks'][$key]??false,$key);
 }
}
