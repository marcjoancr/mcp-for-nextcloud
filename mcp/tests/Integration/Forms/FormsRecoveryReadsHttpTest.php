<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
/** Actual HTTP tools with native rows/files, original authority, scopes and immutable comment archives. */
final class FormsRecoveryReadsHttpTest extends TestCase {
    public function testCompleteEffectInspectionAndRecoveredCommentsThroughHttp():void {
        $this->probe('recovery-reads-http','MCP_FORMS_RECOVERY_READS_HTTP_PROBE',[
            'public_impact_101_read_only','impact_actor_and_integrity','impact_current_and_original_grants','impact_changed_source',
            'whole_form_native_comments_and_shares_cleanup','archive_103_thread_author_read_only','archive_actor_integrity_original_target',
            'archive_current_and_original_grants','file_free_archive_needs_no_generic_files_create','archive_current_original_selection',
            'archive_form_and_global_switches','final_reader_still_admitted']);
    }
    public function testFolderOnlyImpactPreservesCurrentFilesScopesAndOriginalShareAuthority():void {
        $this->probe('recovery-impact-scope-http','MCP_FORMS_RECOVERY_SCOPE_HTTP_PROBE',[
            'folder_only_public_control','original_dynamic_files_share_grant','current_files_scope_excludes_folder',
            'current_and_original_tool_selection','sharing_read_only_after_denials']);
    }
    private function probe(string $name,string $environment,array $checks):void {
        if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')$this->markTestSkipped('Requires an explicitly selected disposable Forms HTTP cell.');
        $file=getenv($environment)?:__DIR__.'/fixtures/'.$name.'.php';self::assertFileExists($file);
        $p=proc_open([PHP_BINARY,$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($p);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$out."\n".$err);
        $result=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($result['ok']);
        foreach($checks as $key)self::assertTrue($result['checks'][$key],$key);
    }
}
