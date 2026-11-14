<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class NativeFormsLegacyPrivacyTest extends TestCase {
    public function testHiddenFileHistoryIsOmittedWhileHistoricalTextRemainsReadable():void {
        if(getenv('MCP_FORMS_NATIVE_BOUNDARY')!=='1')$this->markTestSkipped('Requires disposable native cell.');
        $p=proc_open([PHP_BINARY,getenv('MCP_FORMS_LEGACY_HISTORY_PROBE')?:'/tmp/legacy-file-history.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($p);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($p),$err.' '.$out);$r=json_decode($out,true,32,JSON_THROW_ON_ERROR);self::assertTrue($r['ok'],$out);
        foreach($r['checks'] as $name=>$value)self::assertTrue($value,$name);
    }
}
