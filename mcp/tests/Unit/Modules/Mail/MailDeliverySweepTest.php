<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Transmission\{DeliverySweep,DeliveryRunner};
use OCA\Mcp\Modules\Mail\Integration\MailDeliveryGateway;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
class SweepGatewayFixture extends MailDeliveryGateway {public function queuedAfter(string $cursor,int $limit=25):array {return [];}}
final class MailDeliverySweepTest extends TestCase {
    private static function row(int $id):array {return ['uid'=>'alice','id'=>str_pad(dechex($id),64,'0',STR_PAD_LEFT),'accountId'=>3,'connectionId'=>12,'sourceTool'=>'mail_schedule'];}
    public function testBudgetPersistsOnlyTheLastActuallyReviewedFutureEntry():void {
        $gateway=$this->createMock(SweepGatewayFixture::class);$gateway->expects(self::once())->method('due')->with(25)->willReturn([]);
        $gateway->expects(self::once())->method('queuedAfter')->with('',25)->willReturn(array_map(self::row(...),range(1,5)));
        $config=$this->createMock(IConfig::class);$config->method('getAppValue')->willReturn('');$config->expects(self::once())->method('setAppValue')->with('mcp','mail_delivery_audit_cursor',self::row(3)['id']);
        $now=0;$budgets=[];$seen=[];$actions=[];$runner=$this->createMock(DeliveryRunner::class);$runner->method('run')->willReturnCallback(static function(array $row,string $action='dispatch',?string $repairRevision=null,int $budgetMs=20000)use(&$now,&$budgets,&$seen,&$actions):array{$actions[]=$action;$seen[]=$row['id'];$budgets[]=$budgetMs;$now+=20000000000;return ['status'=>'completed'];});
        (new DeliverySweep($gateway,$runner,$config,static function()use(&$now):int{return $now;}))->run();
        self::assertSame(array_column(array_map(self::row(...),range(1,3)),'id'),$seen);self::assertSame([20000,20000,15000],$budgets);self::assertSame(['audit','audit','audit'],$actions);
    }
    public function testAuditWrapsAndDeduplicatesDueRowsAtFiftyEntries():void {
        $gateway=$this->createMock(SweepGatewayFixture::class);$gateway->method('due')->with(25)->willReturn(array_map(self::row(...),range(20,44)));
        $cursor=self::row(99)['id'];$gateway->expects(self::exactly(2))->method('queuedAfter')->willReturnCallback(static fn(string $after,int $limit):array=>$after===$cursor?[]:array_map(self::row(...),range(1,25)));
        $config=$this->createMock(IConfig::class);$config->method('getAppValue')->willReturn($cursor);$config->expects(self::once())->method('setAppValue')->with('mcp','mail_delivery_audit_cursor',self::row(25)['id']);
        $seen=[];$actions=[];$runner=$this->createMock(DeliveryRunner::class);$runner->method('run')->willReturnCallback(static function(array $row,string $action='dispatch')use(&$seen,&$actions):array{$actions[]=$action;$seen[]=$row['id'];return ['status'=>'completed'];});
        (new DeliverySweep($gateway,$runner,$config,static fn():int=>0))->run();self::assertCount(44,$seen);self::assertSame($seen,array_values(array_unique($seen)));self::assertSame(array_merge(array_fill(0,19,'audit'),array_fill(0,25,'dispatch')),$actions);
    }
}
