<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
/** Explicitly selected disposable native cell; credentials stay in the child process memory. */
final class FormsOwnPublicHttpTest extends TestCase {
    public function testOwnPagesPublicTypedDiscoveryAndCurrentRolesThroughRealHttp():void {
        if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')$this->markTestSkipped('Requires explicitly selected disposable Forms HTTP cell.');
        $probe=getenv('MCP_FORMS_OWN_PUBLIC_HTTP_PROBE')?:__DIR__.'/fixtures/own-public-http.php';
        self::assertFileExists($probe);
        $process=proc_open([PHP_BINARY,$probe],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$out."\n".$err);
        $result=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($result['ok']);$checks=$result['checks'];
        self::assertSame(61,$checks['own_answers']);self::assertTrue($checks['removed_access_and_archive']);
        self::assertTrue($checks['strict_owner_filter']);self::assertTrue($checks['grant_revocation']);
        self::assertTrue($checks['public_schema_and_denials']);self::assertSame(3,$checks['typed_public_families']);
        self::assertTrue($checks['revoked_hash']);self::assertTrue($checks['admin_role_per_tool']);
    }
}
