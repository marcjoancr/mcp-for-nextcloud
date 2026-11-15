<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
/** Host-only Docker fixture: reversible exact synthetic POSIX permissions force native rename/unlink errors. */
final class FormsStorageIoHttpTest extends TestCase {
    public static function positions():array {return ['first'=>[1],'second'=>[2]];}
    #[DataProvider('positions')]
    public function testRealNativeIoFailurePreservesRecoveryAndNeverRepeatsFileEffects(int $position):void {
        if(getenv('MCP_FORMS_LOCAL_IO_BOUNDARY')!=='1')$this->markTestSkipped('Requires explicitly selected disposable Maria Docker cell from its host.');
        $driver=__DIR__.'/fixtures/run-response-local-io.py';self::assertFileExists($driver);
        $p=proc_open(['python3','-I',$driver,(string)$position],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($p);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$out."\n".$err);
        $result=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($result['ok']);self::assertSame($position,$result['position']);
        foreach(['original_response_and_upload_rows_rolled_back','real_renames_reached_exact_fault_position',
            'actual_native_rename_and_unlink_io_failures','fresh_http_recovery_discovery','uncertain_effects_never_replayed',
            'all_original_and_staged_bytes_recovered','new_private_closed_response_bindings',
            'native_write_permission_positive_before_kernel_failure','exact_os_preimages_restored',
            'partial_injection_failure_restores_preimage']as$key)
            self::assertTrue($result['checks'][$key]??false,$key);
    }
}
