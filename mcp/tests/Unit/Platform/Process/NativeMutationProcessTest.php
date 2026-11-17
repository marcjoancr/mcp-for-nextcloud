<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Platform\Process;
use OCA\Mcp\Platform\Process\NativeMutationProcess;
use PHPUnit\Framework\TestCase;
final class NativeMutationProcessTest extends TestCase {
    public function testPerInstanceDeadlineCapDefaultsToTwentySecondsAndCanReachThirty():void {
        self::assertSame(20000,NativeMutationProcess::DEFAULT_MAX_BUDGET_MS);
        self::assertSame(30000,NativeMutationProcess::MAX_BUDGET_MS);
        $process=new NativeMutationProcess(80);$start=microtime(true);
        self::assertSame(['status'=>'unknown'],$process->run([PHP_BINARY,'-r','usleep(300000);echo json_encode(["status"=>"completed"]);'],sys_get_temp_dir(),[],30000));
        self::assertLessThan(0.6,microtime(true)-$start);
        self::assertSame(['status'=>'completed'],(new NativeMutationProcess(30000))->run([PHP_BINARY,'-r','echo json_encode(["status"=>"completed"]);'],sys_get_temp_dir(),[],30000));
    }

    public function testWithBudgetCapClonesTheSharedProcessWithoutChangingItsDefault():void {
        $shared=new class extends NativeMutationProcess {public string $policy='shared';};
        $forms=$shared->withBudgetCap(30000);
        self::assertNotSame($shared,$forms);
        self::assertInstanceOf(get_class($shared),$forms);
        self::assertSame('shared',$forms->policy);
        $budget=new \ReflectionProperty(NativeMutationProcess::class,'maxBudgetMs');
        self::assertSame(20000,$budget->getValue($shared));
        self::assertSame(30000,$budget->getValue($forms));
        try {$shared->withBudgetCap(30001);self::fail('The Forms process cap exceeded the hard limit.');}
        catch (\InvalidArgumentException) {}
    }

    public function testOnlyFixedAcknowledgementsCrossBoundary():void {
        $p=new NativeMutationProcess();
        self::assertSame(['status'=>'completed'],$p->run([PHP_BINARY,'-r','fwrite(STDERR,"SECRET");echo json_encode(["status"=>"completed","secret"=>"SECRET"]);'],sys_get_temp_dir(),[],1000));
        self::assertSame(['status'=>'unknown'],$p->run([PHP_BINARY,'-r','echo "SECRET";'],sys_get_temp_dir(),[],1000));
    }
    public function testWholeDeadlineIncludesTricklingOutputAndUnreadStdin():void {
        $p=new NativeMutationProcess();$start=microtime(true);
        self::assertSame(['status'=>'unknown'],$p->run([PHP_BINARY,'-r','for($i=0;$i<200;$i++){echo " ";usleep(10000);}'],sys_get_temp_dir(),['data'=>str_repeat('x',4000)],60));
        self::assertLessThan(0.8,microtime(true)-$start);
    }
    public function testOversizedInputOrOutputFailsClosed():void {
        $p=new NativeMutationProcess();self::assertSame(['status'=>'unknown'],$p->run([PHP_BINARY,'-r','echo str_repeat("x",5000);'],sys_get_temp_dir(),[],1000));
        self::assertSame(['status'=>'unknown'],$p->run([PHP_BINARY,'-r','echo "must not start";'],sys_get_temp_dir(),['data'=>str_repeat('x',70000)],1000));
    }
    public function testBoundedFolderTreeInputIsAcceptedAndSlowReaderStillExpires():void {
        $p=new NativeMutationProcess();$payload=['data'=>str_repeat('x',60000)];
        self::assertSame(['status'=>'completed'],$p->run([PHP_BINARY,'-r','$s=stream_get_contents(STDIN);echo json_encode(["status"=>strlen($s)>60000&&strlen($s)<65536?"completed":"unknown"]);'],sys_get_temp_dir(),$payload,1000));
        $start=microtime(true);self::assertSame(['status'=>'unknown'],$p->run([PHP_BINARY,'-r','usleep(2000000);'],sys_get_temp_dir(),$payload,60));self::assertLessThan(0.8,microtime(true)-$start);
    }

}
