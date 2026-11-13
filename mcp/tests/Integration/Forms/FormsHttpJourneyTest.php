<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
/** Run only inside an explicitly selected disposable NC35/Maria cell with the companion and production MCP vendor. */
final class FormsHttpJourneyTest extends TestCase {
    public function testRealMcpApprovalAnswersAttachmentsAndRevocableDownload():void {
        if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')$this->markTestSkipped('Requires explicitly selected disposable Forms HTTP cell.');
        $probe=getenv('MCP_FORMS_HTTP_PROBE')?:__DIR__.'/fixtures/http-journey.php';
        self::assertFileExists($probe);
        $p=proc_open([PHP_BINARY,$probe],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($p);fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($p),$out."\n".$err);$result=json_decode($out,true,64,JSON_THROW_ON_ERROR);
        self::assertTrue($result['ok']);self::assertSame(12,$result['checks']['supported_creates']);
        self::assertTrue($result['checks']['web_attachment_save_verified']);self::assertTrue($result['checks']['download_job_complete']);
        self::assertGreaterThan(1000,$result['checks']['xlsx_download_bytes']);self::assertTrue($result['checks']['download_single_use']);
        self::assertTrue($result['checks']['download_revocation_enforced']);
    }
}
