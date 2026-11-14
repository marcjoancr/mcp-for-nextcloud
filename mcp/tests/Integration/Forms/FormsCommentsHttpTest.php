<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
/** Actual HTTP approvals, actor policy, guarded native persistence and historical receipts. */
final class FormsCommentsHttpTest extends TestCase {
    public function testAuthorCrudPermissionsReadMarkersAndHistoricalTargets():void {
        if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')$this->markTestSkipped('Requires explicitly selected disposable native Forms HTTP cell.');
        $probe=getenv('MCP_FORMS_COMMENTS_HTTP_PROBE')?:__DIR__.'/fixtures/comments-public-http.php';
        self::assertFileExists($probe);
        $process=proc_open([PHP_BINARY,$probe],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$out."\n".$err);
        $result=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($result['ok']);
        foreach(['native_access','add_marker_and_no_replay','reads_and_author_projection','strict_author','complete_paging_51','grant_revocation','native_revocation','global_switch','stale_confirmation','form_switch','update_delete_history'] as $key)self::assertTrue($result['checks'][$key],$key);
    }
}
