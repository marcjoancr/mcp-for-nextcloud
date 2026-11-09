<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
use OCP\{IDBConnection,DB\IResult};
final class MailRefreshLeaseTest extends TestCase {
    public function testLostConnectionAndStaleReleaseCannotAffectSuccessor():void {
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Sync/MailboxLease.php';
        $connection=7;$locks=[];$vars=[];$db=$this->createMock(IDBConnection::class);$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);
        $db->method('executeQuery')->willReturnCallback(function($sql,$params=[])use(&$connection,&$locks,&$vars):IResult {
            $v=match(true){str_contains($sql,'DATABASE()')=>'fixture',str_contains($sql,'CONNECTION_ID()')=>$connection,str_contains($sql,'IS_USED_LOCK')=>$locks[$params[0]]??null,str_contains($sql,'GET_LOCK')=>isset($locks[$params[0]])?0:(function()use(&$locks,$params,$connection){$locks[$params[0]]=$connection;return 1;})(),str_contains($sql,'RELEASE_LOCK')=>isset($locks[$params[0]])?(function()use(&$locks,$params){unset($locks[$params[0]]);return 1;})():null,default=>$vars[$sql]??null};
            $r=$this->createMock(IResult::class);$r->method('fetchOne')->willReturn($v);return $r;
        });
        $db->method('executeStatement')->willReturnCallback(function($sql,$params=[])use(&$vars):int {$vars[str_replace('SET ','SELECT ',explode(' = ',$sql)[0])]=$params[0];return 0;});
        $a=\OCA\Mail\Sync\MailboxLease::acquire($db,'oc_',12);$a->assertOwned();self::addToAssertionCount(1);
        $refused=false;try{\OCA\Mail\Sync\MailboxLease::acquire($db,'oc_',12);}catch(\RuntimeException){$refused=true;}self::assertTrue($refused);
        // Server closes old session. The same DB wrapper reconnects and a successor acquires.
        $connection=8;$locks=[];$vars=[];$b=\OCA\Mail\Sync\MailboxLease::acquire($db,'oc_',12);$a->release();$b->assertOwned();self::addToAssertionCount(1);
        $this->expectException(\RuntimeException::class);$a->assertOwned();
    }
}
