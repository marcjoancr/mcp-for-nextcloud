<?php
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCP\{IDBConnection,DB\IResult};
use PHPUnit\Framework\TestCase;
final class PgSessionLeaseTest extends TestCase {
    public function testPostgresLeaseChecksSessionMarkerAndRefusesRecursiveLock():void {
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Db/PgSessionLease.php';
        $pid=41;$lockPid=41;$start='2026-11-10 10:00:00+00';$lock=false;$unlocks=0;$settings=[];$db=$this->createMock(IDBConnection::class);
        $db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
        $db->method('executeQuery')->willReturnCallback(function($sql,$params=[])use(&$pid,&$lockPid,&$start,&$lock,&$unlocks,&$settings):IResult {
            $v=match(true){str_contains($sql,'pg_backend_pid')&&str_starts_with($sql,'SELECT pg_backend_pid')=>$pid,str_contains($sql,'backend_start')=>$start,str_contains($sql,'pg_try_advisory_lock')=>($lock?false:(function()use(&$lock,&$lockPid,$pid){$lock=true;$lockPid=$pid;return true;})()),str_contains($sql,'pg_advisory_unlock')=>(function()use(&$unlocks,&$lock,&$lockPid,$pid){$unlocks++;if($lockPid===$pid){$lock=false;return true;}return false;})(),str_contains($sql,'current_setting')=>$settings[$params[0]]??null,str_contains($sql,'set_config')=>($settings[$params[0]]=$params[1]),str_contains($sql,'pg_locks')=>($lock&&(!str_contains($sql,'pid=pg_backend_pid()')||$lockPid===$pid)),default=>null};
            $r=$this->createMock(IResult::class);$r->method('fetchOne')->willReturn($v);return $r;
        });
        $lease=\OCA\Mail\Db\PgSessionLease::acquire($db,'oc_','draft','alice');$lease->assertOwned();self::addToAssertionCount(1);
        try {\OCA\Mail\Db\PgSessionLease::acquire($db,'oc_','draft','alice');self::fail('Recursive acquisition must fail.');}catch(\RuntimeException){}
        $db->method('inTransaction')->willReturn(true);
        try {\OCA\Mail\Db\PgSessionLease::acquire($db,'oc_','draft','another');self::fail('A transaction cannot safely establish a session marker.');}catch(\RuntimeException){}
        $db->method('inTransaction')->willReturn(false);
        $lockPid=99;
        try {$lease->assertOwned();self::fail('A different backend holding the key is not this lease.');}catch(\RuntimeException){}
        $lease->release();self::assertSame(0,$unlocks,'A stale lease must not unlock the successor.');
    }
}
