<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
/** Disposable HTTP cell; unavailable file history, native ownership and independent recovery. */
final class FormsHistoryLifecycleHttpTest extends TestCase {
 public function testUnavailableFileHistoryStaysPrivateAcrossNativeOwnershipAndRecovery():void {
  if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')$this->markTestSkipped('Requires explicitly selected disposable Forms HTTP cell.');
  $fixture=getenv('MCP_FORMS_HISTORY_HTTP_PROBE')?:__DIR__.'/fixtures/history-lifecycle-http.php';self::assertFileExists($fixture);
  $p=proc_open([PHP_BINARY,$fixture],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($p);
  fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  self::assertSame(0,proc_close($p),$out."\n".$err);$r=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($r['ok']);
  foreach(['public_hide_keeps_native_history_and_unmanaged_bytes','public_history2_archive_reports_unavailable_bytes',
   'file_free_history_restore_with_no_files_create_or_scope','partial_scope_setup_restores_both_preimages','native_and_legacy_http_hide_null_file_names',
   'native_http_owner_transfer_works_with_mcp_disabled_and_unenrolled_target',
   'new_owner_password_change_rearchive_and_restore','old_owner_backup_remains_private']as$key)self::assertTrue($r['checks'][$key]??false,$key);
 }
}
