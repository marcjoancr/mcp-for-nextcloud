<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
/** Real native DB/Files and MCP policy services; the public bulk writer remains separately qualified. */
final class FormsBulkPreviewNativeTest extends TestCase {
    public function testEveryEffectIsAuthorizedAndOpaqueInspectionIsReadOnlyAndFresh():void {
        $this->probe('bulk-preview','MCP_FORMS_BULK_PREVIEW_PROBE',
            ['all_managed_scopes','unmanaged_not_selected','complete_opaque_inspection','read_only','original_grant_revocation','changed_source_refused']);
    }
    public function testAnExcludedManagedShareFolderCannotBypassScopesWhenThereAreNoFiles():void {
        $this->probe('bulk-preview-scope','MCP_FORMS_BULK_SCOPE_PROBE',
            ['native_folder_only_control','excluded_preview_denied','excluded_inspection_denied']);
    }
    private function probe(string $fixture,string $environment,array $checks):void {
        if(getenv('MCP_FORMS_NATIVE_BOUNDARY')!=='1')$this->markTestSkipped('Requires an explicitly selected disposable native Forms cell.');
        $probe=getenv($environment)?:__DIR__.'/fixtures/'.$fixture.'.php';self::assertFileExists($probe);
        $p=proc_open([PHP_BINARY,$probe],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($p);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$out."\n".$err);
        $r=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($r['ok']);
        foreach($checks as $key)self::assertTrue($r['checks'][$key],$key);
    }
}
