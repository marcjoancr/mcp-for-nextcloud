<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
/** Private real CLI/DB/Files cycle. Initial native association is seeded separately from the public MCP flow. */
final class FormsLinkedMcpTest extends TestCase {
    /** @dataProvider recoveryModes */
    public function testRealIsolatedCycleAndOriginalGrantRevocation(string $mode):void {
        if(getenv('MCP_FORMS_LINKED_BOUNDARY')!=='1')$this->markTestSkipped('Requires explicitly selected disposable native Forms cell.');
        $probe=getenv('MCP_FORMS_LINKED_PROBE')?:__DIR__.'/fixtures/linked-mcp.php';self::assertFileExists($probe);
        $process=proc_open([PHP_BINARY,$probe],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($process);
        fwrite($pipes[0],json_encode(['case'=>$mode],JSON_THROW_ON_ERROR));fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$out."\n".$err);$result=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($result['ok']);
        foreach(['isolated_cycle','complete_source','revocation_pauses','restoration_does_not_resume'] as $check)self::assertTrue($result['checks'][$check],$check);
    }
    public static function recoveryModes():iterable {yield 'native Versions'=>['mcp_worker'];yield 'private copy'=>['copies_true'];}
}
