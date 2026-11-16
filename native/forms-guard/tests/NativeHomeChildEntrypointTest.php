<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NativeHomeChildEntrypointTest extends TestCase {
    public function testLegacyNullControlRunsTheRealChildEntrypointWithOriginalProof(): void {
        $origin = ['signed'=>'original-proof'];
        $jobId = str_repeat('a', 64);
        $phase = ['nonce'=>str_repeat('b',64),'phase'=>'copy_files','uid'=>'actor','action'=>'forms_transfer',
            'target_uid'=>'recipient','step_key'=>str_repeat('c',24),'version'=>1];
        $envelope = ['job_id'=>$jobId,'uid'=>'actor','origin'=>$origin,'control'=>null,'home_phase'=>$phase];
        $input = ['envelope'=>$envelope,'job'=>['id'=>$jobId,'uid'=>'actor','action'=>'forms_transfer','input'=>[],
            'native_home_claim'=>['home_phase'=>$phase]]];
        $command = [PHP_BINARY, __DIR__.'/fixtures/native-home-child-null-control.php', __DIR__.'/../src/Service/NativeHomeChild.php'];
        $pipes = [];
        $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__, 3), ['PATH'=>getenv('PATH') ?: '/usr/bin:/bin'], ['bypass_shell'=>true]);
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, $stderr);
        $receipt = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('Native Home child cannot borrow a Forms transaction', $receipt['error']);
        self::assertSame([$origin, $origin], $receipt['seen']);
    }

    public function testStorageModePreservesOrdinaryFormsSinksAndGuardsOnlyHomeSetup(): void {
        $command = [PHP_BINARY, __DIR__.'/fixtures/native-home-storage-mode.php', __DIR__.'/../src/Service/NativeHomeStorage.php',
            __DIR__.'/../src/Service/NativeHomeScanner.php', __DIR__.'/../src/Service/NativeHomePath.php'];
        $pipes = [];
        $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__, 3), ['PATH'=>getenv('PATH') ?: '/usr/bin:/bin'], ['bypass_shell'=>true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, $stderr);
        self::assertSame(['scanner_outer_chain_preserved'=>true,'ordinary_mkdir_noop'=>true,
            'ordinary_write_delegated'=>true,'guarded_collision_denied'=>true],
            json_decode($stdout, true, 16, JSON_THROW_ON_ERROR));
    }
}
