<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class FormsSpoolNativeTest extends TestCase {
    public function testNativeCryptoQuotaAndExpiredCacheCleanup():void {
        if(getenv('MCP_FORMS_SPOOL_BOUNDARY')!=='1')$this->markTestSkipped('Requires an explicitly selected disposable native Forms quota cell.');
        $probe=getenv('MCP_FORMS_SPOOL_PROBE')?:__DIR__.'/fixtures/spool.php';self::assertFileExists($probe);
        $process=proc_open([PHP_BINARY,$probe],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$out."\n".$error);$result=json_decode($out,true,64,JSON_THROW_ON_ERROR);
        self::assertTrue($result['ok']);self::assertSame(26214400,$result['checks']['plain_bytes']);self::assertSame(52428996,$result['checks']['cipher_bytes']);
        foreach(['seal_exact','cleanup_verified','late_write_refused','receipts_unchanged'] as $check)self::assertTrue($result['checks'][$check]);
        self::assertSame('OC\\Lock\\DBLockingProvider',$result['checks']['provider']);
    }
}
