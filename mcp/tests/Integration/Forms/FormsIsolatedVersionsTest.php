<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
/** Native Versions feasibility only; deferred Forms authority has a separate qualification matrix. */
final class FormsIsolatedVersionsTest extends TestCase {
    public function testSeparateNullSessionChildrenPreserveEachOriginalActorsNativeVersion():void {
        if(getenv('MCP_FORMS_VERSIONS_BOUNDARY')!=='1')$this->markTestSkipped('Requires an explicitly selected disposable native Versions cell.');
        $probe=getenv('MCP_FORMS_VERSIONS_PROBE')?:__DIR__.'/fixtures/isolated-versions.php';self::assertFileExists($probe);
        $process=proc_open([PHP_BINARY,$probe],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($process);
        fwrite($pipes[0],json_encode(['mode'=>'suite'],JSON_THROW_ON_ERROR));fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$out."\n".$error);$result=json_decode($out,true,16,JSON_THROW_ON_ERROR);
        self::assertTrue($result['ok']);self::assertSame(2,$result['checks']['separate_children']);
        foreach(['null_session','correct_versions','entities','cross_actor_isolation'] as $check)self::assertTrue($result['checks'][$check]);
    }
}
