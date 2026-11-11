<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCA\Mcp\Modules\Mail\Integration\MailSnoozeGateway;
use OCA\Mcp\Modules\Mail\Snooze\{SnoozeSweep,SnoozeRunner};
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
/** Real native selection SQL plus actual sweep, with stalled cached destinations left eligible. */
final class MailSnoozeFairDispatchTest extends TestCase {
    private static function id(int $i):string {return str_pad(dechex($i),64,'0',STR_PAD_LEFT);}
    public function testPreparedAndDueBehindTwentyFiveWaitingCacheRowsProgressAcrossSweeps():void {
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Snooze/SnoozeStore.php';
        $db=new SqliteDatabase($this,[]);$db->dbal->executeStatement('CREATE TABLE mail_mcp_snoozes (id TEXT,user_id TEXT,account_id INTEGER,connection_id INTEGER,state TEXT,placement_phase TEXT,wake_phase TEXT,wake_at INTEGER)');
        foreach(range(1,29)as$i)$db->dbal->insert('mail_mcp_snoozes',['id'=>self::id($i),'user_id'=>'alice','account_id'=>3,'connection_id'=>4,'state'=>$i===26?'placing':'snoozed','placement_phase'=>$i===26?'prepared':($i===29?'wire_may_start':'completed'),'wake_phase'=>'prepared','wake_at'=>$i===26||$i===28?time()+3600:time()-3600]);
        $values=[];
        // Capture by reference so each next sweep reads the cursor actually persisted by the previous one.
        $config=$this->createMock(IConfig::class);$config->method('getAppValue')->willReturnCallback(static function($app,$key,$default)use(&$values){return $values[$key]??$default;});$config->method('setAppValue')->willReturnCallback(static function($app,$key,$value)use(&$values):void{$values[$key]=$value;});
        $native=new \OCA\Mail\Snooze\SnoozeStore($db->connection(),$config);
        $gateway=new class($native)extends MailSnoozeGateway{public function __construct(private object $native){}public function due(int $limit=25,string $cursor=''):array{return $this->native->due($limit,$cursor);}public function queuedAfter(string $cursor,int $limit=25):array{return $this->native->queuedAfter($cursor,$limit);}};
        $attempts=[];$runner=$this->createMock(SnoozeRunner::class);$runner->method('run')->willReturnCallback(static function(array $r,string $action='dispatch')use(&$attempts):array{$attempts[$r['id']][]=$action;return ['status'=>'completed'];});$sweep=new SnoozeSweep($gateway,$runner,$config,static fn():int=>0);
        for($i=0;$i<3;$i++)$sweep->run();
        self::assertContains('dispatch',$attempts[self::id(26)]??[],'Future prepared placement must not starve behind waiting_cache.');self::assertContains('dispatch',$attempts[self::id(27)]??[],'Ready due wake must not starve.');
        self::assertNotContains('dispatch',$attempts[self::id(28)]??[],'Future snoozed rows only audit.');self::assertArrayNotHasKey(self::id(29),$attempts,'Unknown wire legs never execute or lose holds.');
    }
}
