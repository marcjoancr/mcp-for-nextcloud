<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;

final class MailHarnessTest extends TestCase {
    private function runCommand(array $command, ?array $environment = null): array {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $out, $error];
    }

    public function testMailIsAnExplicitPinnedOptionAndUnsupportedCellsAreRejected(): void {
        $script = dirname(__DIR__, 3) . '/tests/Integration/ci/run-local.sh';
        foreach ([['35','fresh'],['35','upgrade-local'],['33','fresh'],['34','fresh']] as [$core,$mode]) {
            [$status, $out, $error] = $this->runCommand(['sh', $script, '--plan', $core, 'mariadb', $mode, '--mail']);
            self::assertSame(0, $status, $error);
            $plan = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse($plan['publishes_ports']);
            self::assertTrue($plan['disposable']);
            self::assertSame('5.12.5', $plan['mail']['mail_version']);
            self::assertMatchesRegularExpression('/@sha256:[a-f0-9]{64}$/', $plan['mail']['imap_image']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plan['mail']['mail_sha256']);
            self::assertSame('5.4.1', $plan['forms']['forms_version']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plan['forms']['forms_sha256']);
        }
        [$status, $out] = $this->runCommand(['sh', $script, '--plan', '35', 'mariadb', 'fresh']);
        self::assertSame(0, $status);
        self::assertNull(json_decode($out, true, 512, JSON_THROW_ON_ERROR)['mail']);
        foreach ([['33','mariadb','upgrade-local'],['34','mariadb','upgrade-local'],['35', 'pgsql', 'fresh'], ['35', 'mariadb', 'upgrade']] as $cell) {
            [$status, $out] = $this->runCommand(['sh', $script, '--plan', ...$cell, '--mail']);
            self::assertSame(2, $status);
            self::assertSame('', $out);
        }
    }

    public function testGuardedExtendedCellRequiresAnExplicitQualificationPhase():void {
        $script=dirname(__DIR__,3).'/tests/Integration/ci/run-local.sh';
        foreach(['33','34'] as $core){
            $env=array_merge(getenv(),['MCP_IT_MAIL_GUARD'=>'1','MCP_IT_MAIL_RUNTIME_PHASE'=>'']);
            [$status]=$this->runCommand(['sh',$script,'--plan',$core,'mariadb','fresh','--mail'],$env);
            self::assertSame(2,$status);
            $env['MCP_IT_MAIL_RUNTIME_PHASE']='candidate';
            [$status,$out,$error]=$this->runCommand(['sh',$script,'--plan',$core,'mariadb','fresh','--mail'],$env);
            self::assertSame(2,$status);self::assertSame('',$out);self::assertStringContainsString('pre-admission source',$error);
            $env['MCP_IT_MAIL_RUNTIME_PHASE']='enabled';
            [$status,$out]=$this->runCommand(['sh',$script,'--plan',$core,'mariadb','fresh','--mail'],$env);
            self::assertSame(0,$status);self::assertSame('enabled',json_decode($out,true,512,JSON_THROW_ON_ERROR)['mail_runtime_phase']);
            $env['MCP_IT_MAIL_RUNTIME_PHASE']='unknown';
            [$status]=$this->runCommand(['sh',$script,'--plan',$core,'mariadb','fresh','--mail'],$env);
            self::assertSame(2,$status);
        }
    }

    public function testCandidateAcceptsOnlyPreAdmissionSourceAndEnabledRejectsIt():void {
        $temp=sys_get_temp_dir().'/mcp-mail-pre-admission-'.bin2hex(random_bytes(6));
        $root=dirname(__DIR__,3);
        $files=['tests/Integration/ci/matrix.php','tests/Integration/ci/matrix.json','tests/Integration/ci/mail-pins.json','tests/Integration/ci/forms-pins.json','lib/Modules/Mail/Integration/MailRuntimePairs.php','lib/Modules/Mail/Integration/NativeGuardManifest.php'];
        $dirs=[];
        try{
            foreach($files as $file){
                $parent=dirname($temp.'/'.$file);$missing=[];
                while(!is_dir($parent)){$missing[]=$parent;$parent=dirname($parent);}
                foreach(array_reverse($missing) as $dir){mkdir($dir,0700);$dirs[]=$dir;}
                copy($root.'/'.$file,$temp.'/'.$file);
            }
            $policy=$temp.'/lib/Modules/Mail/Integration/MailRuntimePairs.php';
            $closed=preg_replace('/public const EXTENDED_CORES=\\[[^;]*\\];/','public const EXTENDED_CORES=[];',file_get_contents($policy),1,$count);
            self::assertSame(1,$count);file_put_contents($policy,$closed);
            foreach(['33','34'] as $core){
                $env=array_merge(getenv(),['MCP_IT_MAIL_GUARD'=>'1','MCP_IT_MAIL_RUNTIME_PHASE'=>'candidate']);
                [$status,$out,$error]=$this->runCommand([PHP_BINARY,$temp.'/tests/Integration/ci/matrix.php',$core,'mariadb','fresh','--mail'],$env);
                self::assertSame(0,$status,$error);self::assertSame('candidate',json_decode($out,true,512,JSON_THROW_ON_ERROR)['mail_runtime_phase']);
                $env['MCP_IT_MAIL_RUNTIME_PHASE']='enabled';
                [$status,$out,$error]=$this->runCommand([PHP_BINARY,$temp.'/tests/Integration/ci/matrix.php',$core,'mariadb','fresh','--mail'],$env);
                self::assertSame(2,$status);self::assertSame('',$out);self::assertStringContainsString('requires source admitting',$error);
            }
        }finally{
            foreach($files as $file)if(is_file($temp.'/'.$file))unlink($temp.'/'.$file);
            foreach(array_reverse($dirs) as $dir)if(is_dir($dir))rmdir($dir);
        }
    }

    public function testQualificationCannotValidateAnExternalKitUsingCheckoutPolicy():void {
        $script=dirname(__DIR__,3).'/tests/Integration/ci/run-local.sh';
        foreach(['enabled','candidate'] as $phase){
            $env=array_merge(getenv(),['MCP_IT_MAIL_GUARD'=>'1','MCP_IT_MAIL_RUNTIME_PHASE'=>$phase]);
            [$status,$out,$error]=$this->runCommand(['sh',$script,'--plan','--candidate-kit','/not-a-qualification-source',str_repeat('a',64),'33','mariadb','fresh','--mail'],$env);
            self::assertSame(2,$status);self::assertSame('',$out);self::assertStringContainsString('selected checkout source',$error);
        }
    }

    public function testProvisionRefusesAnUnrelatedContainerBeforeAnyMutation(): void {
        $temp = sys_get_temp_dir() . '/mcp-mail-guard-' . bin2hex(random_bytes(6));
        mkdir($temp, 0700);
        $docker = $temp . '/docker';
        file_put_contents($docker, "#!/bin/sh\nif [ \"\$1\" = inspect ]; then echo other-run; else echo mutation >&2; exit 99; fi\n");
        chmod($docker, 0700);
        try {
            $environment = array_merge(getenv(), ['PATH' => $temp . ':' . getenv('PATH'), 'MCP_IT_LOCAL' => '1', 'MCP_IT_WITH_MAIL' => '1',
                'MCP_IT_RUN_ID' => 'ours', 'MCP_IT_NET' => 'ours-net', 'MCP_IT_NC_NAME' => 'foreign', 'MCP_IT_IMAP_NAME' => 'ours-imap', 'RUNNER_TEMP' => $temp]);
            [$status, $out, $error] = $this->runCommand(['sh', dirname(__DIR__, 3) . '/tests/Integration/ci/mail-cell.sh', 'provision'], $environment);
            self::assertSame(2, $status);
            self::assertSame('', $out);
            self::assertStringContainsString('Refusing unrelated', $error);
            self::assertStringNotContainsString('mutation', $error);
        } finally {
            unlink($docker); rmdir($temp);
        }
    }
}
