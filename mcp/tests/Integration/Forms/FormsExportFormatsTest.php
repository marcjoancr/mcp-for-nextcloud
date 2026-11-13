<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
/** Uses the exact spreadsheet reader bundled with the pinned disposable native Forms app. */
final class FormsExportFormatsTest extends TestCase {
    public function testActualCsvOdsAndXlsxRoundTrip():void {
        if(getenv('MCP_FORMS_FORMAT_BOUNDARY')!=='1')$this->markTestSkipped('Requires explicitly selected disposable Forms format cell.');
        $probe=getenv('MCP_FORMS_FORMAT_PROBE')?:__DIR__.'/fixtures/export-formats.php';
        $p=proc_open([PHP_BINARY,$probe],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($p);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($p),$out."\n".$err);
        $result=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($result['ok']);
        foreach(['csv','ods','xlsx'] as $format)self::assertTrue($result['checks'][$format]['round_trip']);
    }
}
