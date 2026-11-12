<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Platform\Process;
use OCA\Mcp\Platform\Process\NativeMutationProcess;
use PHPUnit\Framework\TestCase;
final class NativeMutationProcessTest extends TestCase {
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
