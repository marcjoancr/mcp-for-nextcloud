<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedExportRunner;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedSweep;
use OCA\Mcp\Platform\Process\NativeMutationProcess;
use PHPUnit\Framework\TestCase;
final class FormsLinkedSweepTest extends TestCase {
    public function testSweepRequiresFullThirtySecondBudgetAndRechecksDeferredWorkNextFairPage():void {
        $clock=0;$calls=[];$ids=[str_repeat('a',64),str_repeat('b',64),str_repeat('c',64)];$pages=[$ids,[$ids[2],$ids[0],$ids[1]]];
        $process=$this->createMock(NativeMutationProcess::class);
        $process->method('withBudgetCap')->willReturn($process);
        $process->method('run')->willReturnCallback(static function(array $argv,string $cwd,array $payload,int $budget)use(&$clock,&$calls):array {
            $calls[]=[$payload['association_id'],$budget];$clock+=20000000000;return ['status'=>'completed'];
        });
        $runner=new FormsLinkedExportRunner($process,static fn():array=>['command'=>['/usr/bin/php','/srv/occ'],'cwd'=>'/srv']);
        $sweep=new FormsLinkedSweep($runner,static function(int $limit)use(&$pages):array {self::assertSame(3,$limit);return array_shift($pages)??[];},static function()use(&$clock):int {return $clock;});
        $sweep->run();self::assertSame([[$ids[0],30000],[$ids[1],30000]],$calls);
        $sweep->run();self::assertSame([[$ids[0],30000],[$ids[1],30000],[$ids[2],30000],[$ids[0],30000]],$calls);
    }
    public function testSweepDoesNotPassAClippedBudgetWhenOnlyTwentyNineSecondsRemain():void {
        $clock=0;$calls=0;$process=$this->createMock(NativeMutationProcess::class);
        $process->method('withBudgetCap')->willReturn($process);
        $process->expects(self::once())->method('run')->willReturnCallback(static function()use(&$clock,&$calls):array {$calls++;$clock=26001000000;return ['status'=>'completed'];});
        $runner=new FormsLinkedExportRunner($process,static fn():array=>['command'=>['/usr/bin/php','/srv/occ'],'cwd'=>'/srv']);
        (new FormsLinkedSweep($runner,static fn():array=>[str_repeat('a',64),str_repeat('b',64)],static function()use(&$clock):int {return $clock;}))->run();
        self::assertSame(1,$calls);
    }
    public function testUnknownAttemptIsNotRepeatedWithinOneSweep():void {
        $process=$this->createMock(NativeMutationProcess::class);$process->expects(self::once())->method('run')->willReturn(['status'=>'unknown']);
        $process->method('withBudgetCap')->willReturn($process);
        $runner=new FormsLinkedExportRunner($process,static fn():array=>['command'=>['/usr/bin/php','/srv/occ'],'cwd'=>'/srv']);
        (new FormsLinkedSweep($runner,static fn():array=>[str_repeat('a',64)]))->run();
    }
    public function testExpiredSweepDoesNotStartAnotherChild():void {
        $clock=0;$process=$this->createMock(NativeMutationProcess::class);
        $process->method('withBudgetCap')->willReturn($process);
        $process->expects(self::once())->method('run')->willReturnCallback(static function()use(&$clock):array {$clock=55000000000;return ['status'=>'unknown'];});
        $runner=new FormsLinkedExportRunner($process,static fn():array=>['command'=>['/usr/bin/php','/srv/occ'],'cwd'=>'/srv']);
        (new FormsLinkedSweep($runner,static fn():array=>[str_repeat('a',64),str_repeat('b',64)],static function()use(&$clock):int {return $clock;}))->run();
    }
    /** @dataProvider invalidPages */
    public function testMalformedOrDuplicateNativeQueueCannotStartAWriter(array $ids):void {
        $process=$this->createMock(NativeMutationProcess::class);$process->expects(self::never())->method('run');
        $process->method('withBudgetCap')->willReturn($process);
        $runner=new FormsLinkedExportRunner($process,static fn():array=>['command'=>['/usr/bin/php','/srv/occ'],'cwd'=>'/srv']);
        (new FormsLinkedSweep($runner,static fn():array=>$ids))->run();self::assertTrue(true);
    }
    public static function invalidPages():iterable {
        yield [['invalid']];yield [[str_repeat('a',64),str_repeat('a',64)]];yield [array_fill(0,4,str_repeat('a',64))];
    }
}
