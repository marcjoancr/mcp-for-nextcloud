<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
/** Real persistent OAuth HTTP approval, initial recovery, cron update and unlink preserving output. */
final class FormsLinkedPublicHttpTest extends TestCase {
    /** @dataProvider modes */
    public function testPublicActivationRecoveryCronAndUnlink(string $mode):void {
        if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')$this->markTestSkipped('Requires explicitly selected disposable native Forms HTTP cell.');
        $probe=getenv('MCP_FORMS_LINKED_PUBLIC_PROBE')?:__DIR__.'/fixtures/linked-public-http.php';
        self::assertFileExists($probe);
        $process=proc_open([PHP_BINARY,$probe],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($process);
        fwrite($pipes[0],json_encode(['mode'=>$mode],JSON_THROW_ON_ERROR));fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$out."\n".$err);
        $result=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($result['ok']);
        foreach(['persistent_http_initial_link','real_cron_updates','unlink_preserves_file'] as $key)self::assertTrue($result['checks'][$key],$key);
        self::assertSame($mode,$result['checks']['initial_recovery']);
    }
    public static function modes():iterable {yield 'new output'=>['create'];yield 'native Versions overwrite'=>['versions'];yield 'private copy overwrite'=>['copy'];}
}
