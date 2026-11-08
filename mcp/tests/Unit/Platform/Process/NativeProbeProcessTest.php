<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Platform\Process;
use OCA\Mcp\Platform\Process\NativeProbeProcess;
use PHPUnit\Framework\TestCase;
final class NativeProbeProcessTest extends TestCase {
    public function testOnlyWhitelistedResultsCrossThePrivateProcessBoundary(): void {
        $p=new NativeProbeProcess();
        $r=$p->run([PHP_BINARY,'-r','fwrite(STDERR,"SECRET"); echo json_encode(["status"=>"ok","category"=>"authenticated","password"=>"SECRET"]);'],sys_get_temp_dir(),[],1000);
        self::assertSame(['status'=>'ok','category'=>'authenticated'],$r);
        $r=$p->run([PHP_BINARY,'-r','echo "SECRET transcript";'],sys_get_temp_dir(),[],1000);
        self::assertSame(['status'=>'unknown','category'=>'unverified_result'],$r);
    }
    public function testATricklingNativeTransportCannotExceedItsWallClockBudget(): void {
        $p=new NativeProbeProcess();$start=microtime(true);
        $r=$p->run([PHP_BINARY,'-r','usleep(1000000);echo "secret";'],sys_get_temp_dir(),[],50);
        self::assertSame(['status'=>'unknown','category'=>'timeout'],$r);
        self::assertLessThan(0.8,microtime(true)-$start);
    }
}
