<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Snooze\{SnoozeSweep,SnoozeRunner};
use OCA\Mcp\Modules\Mail\Integration\MailSnoozeGateway;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
final class MailSnoozeSweepTest extends TestCase {
    private static function row(int $id):array {return ['uid'=>'alice','id'=>str_pad(dechex($id),64,'0',STR_PAD_LEFT),'accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_snooze'];}
    private function config(array &$values):IConfig {$c=$this->createMock(IConfig::class);$c->method('getAppValue')->willReturnCallback(static function($app,$key,$default)use(&$values){return $values[$key]??$default;});$c->method('setAppValue')->willReturnCallback(static function($app,$key,$value)use(&$values):void{$values[$key]=$value;});return $c;}
    public function testBudgetPersistsOnlyTheLastActuallyReviewedFutureEntry():void {
        $gateway=$this->createMock(MailSnoozeGateway::class);$gateway->expects(self::once())->method('due')->with(25,'')->willReturn([]);$gateway->expects(self::once())->method('queuedAfter')->with('',25)->willReturn(array_map(self::row(...),range(1,5)));
        $values=[];$config=$this->config($values);$now=0;$budgets=[];$seen=[];$runner=$this->createMock(SnoozeRunner::class);$runner->method('run')->willReturnCallback(static function(array $row,string $action='dispatch',?string $revision=null,?string $operation=null,int $budgetMs=20000)use(&$now,&$budgets,&$seen):array{$seen[]=$row['id'];$budgets[]=$budgetMs;$now+=20000000000;return ['status'=>'unknown'];});
        (new SnoozeSweep($gateway,$runner,$config,static function()use(&$now):int{return $now;}))->run();self::assertSame(array_column(array_map(self::row(...),range(1,3)),'id'),$seen);self::assertSame([20000,20000,15000],$budgets);self::assertSame(self::row(3)['id'],$values['mail_snooze_audit_cursor']);
    }
    public function testBothPagesWrapAndDeduplicateAtFiftyEntries():void {
        $gateway=$this->createMock(MailSnoozeGateway::class);$cursor=self::row(99)['id'];$gateway->expects(self::exactly(2))->method('due')->willReturnCallback(static fn(int $limit,string $after):array=>$after===$cursor?[]:array_map(self::row(...),range(20,44)));$gateway->expects(self::exactly(2))->method('queuedAfter')->willReturnCallback(static fn(string $after,int $limit):array=>$after===$cursor?[]:array_map(self::row(...),range(1,25)));
        $values=['mail_snooze_audit_cursor'=>$cursor,'mail_snooze_dispatch_cursor'=>$cursor];$config=$this->config($values);$seen=[];$actions=[];$runner=$this->createMock(SnoozeRunner::class);$runner->method('run')->willReturnCallback(static function(array $row,string $action='dispatch')use(&$seen,&$actions):array{$actions[]=$action;$seen[]=$row['id'];return ['status'=>'completed'];});
        (new SnoozeSweep($gateway,$runner,$config,static fn():int=>0))->run();self::assertCount(44,$seen);self::assertSame($seen,array_values(array_unique($seen)));self::assertSame(25,count(array_filter($actions,static fn($a)=>$a==='dispatch')));self::assertSame(19,count(array_filter($actions,static fn($a)=>$a==='audit')));self::assertSame(self::row(44)['id'],$values['mail_snooze_dispatch_cursor']);self::assertSame(self::row(25)['id'],$values['mail_snooze_audit_cursor']);
    }
    public function testShortBudgetInterleavesAndNeverSkipsAnUnattemptedPrefix():void {
        $gateway=$this->createMock(MailSnoozeGateway::class);$gateway->method('due')->willReturn(array_map(self::row(...),range(20,44)));$gateway->method('queuedAfter')->willReturn(array_map(self::row(...),range(1,25)));
        $values=[];$config=$this->config($values);$now=0;$seen=[];$budgets=[];$runner=$this->createMock(SnoozeRunner::class);$runner->method('run')->willReturnCallback(static function(array $r,string $action,?string $revision=null,?string $operation=null,int $budgetMs=20000)use(&$now,&$seen,&$budgets):array{$seen[]=[$r['id'],$action];$budgets[]=$budgetMs;$now+=20000000000;return ['status'=>'unknown'];});
        (new SnoozeSweep($gateway,$runner,$config,static function()use(&$now):int{return $now;}))->run();self::assertSame([[self::row(20)['id'],'dispatch'],[self::row(1)['id'],'audit'],[self::row(21)['id'],'dispatch']],$seen);self::assertSame([20000,20000,15000],$budgets);self::assertSame(self::row(21)['id'],$values['mail_snooze_dispatch_cursor']);self::assertSame(self::row(1)['id'],$values['mail_snooze_audit_cursor']);
    }
}
