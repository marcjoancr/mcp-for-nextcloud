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
        foreach (['fresh', 'upgrade-local'] as $mode) {
            [$status, $out, $error] = $this->runCommand(['sh', $script, '--plan', '35', 'mariadb', $mode, '--mail']);
            self::assertSame(0, $status, $error);
            $plan = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse($plan['publishes_ports']);
            self::assertTrue($plan['disposable']);
            self::assertSame('5.12.5', $plan['mail']['mail_version']);
            self::assertMatchesRegularExpression('/@sha256:[a-f0-9]{64}$/', $plan['mail']['imap_image']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plan['mail']['mail_sha256']);
        }
        [$status, $out] = $this->runCommand(['sh', $script, '--plan', '35', 'mariadb', 'fresh']);
        self::assertSame(0, $status);
        self::assertNull(json_decode($out, true, 512, JSON_THROW_ON_ERROR)['mail']);
        foreach ([['34', 'mariadb', 'fresh'], ['35', 'pgsql', 'fresh'], ['35', 'mariadb', 'upgrade']] as $cell) {
            [$status, $out] = $this->runCommand(['sh', $script, '--plan', ...$cell, '--mail']);
            self::assertSame(2, $status);
            self::assertSame('', $out);
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
