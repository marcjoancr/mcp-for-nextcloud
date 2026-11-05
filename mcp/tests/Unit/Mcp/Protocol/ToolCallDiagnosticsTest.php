<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Protocol;

use OCA\Mcp\Mcp\Diagnostics\ToolCallDiagnostics;
use OCA\Mcp\Mcp\Protocol\ToolRequests;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Modules\Files\Sharing\SharingMessages;
use OCA\Mcp\Tests\Unit\InstalledApp;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class ToolCallDiagnosticsTest extends TestCase {
    private string $journal;
    private array $settings;
    private ToolCallDiagnostics $diagnostics;

    protected function setUp(): void {
        $this->journal = tempnam(sys_get_temp_dir(), 'diagnostics-');
        $this->settings = ['user' => 'target', 'file' => $this->journal, 'until' => time() + 300, 'tools' => ['files_share']];
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValue')->willReturnCallback(fn () => $this->settings);
        $config->method('getSystemValueString')->willReturn(bin2hex(random_bytes(16)));
        $this->diagnostics = new ToolCallDiagnostics($config, SharingMessages::diagnosticReason(...));
    }

    protected function tearDown(): void {
        unlink($this->journal);
    }

    public function testJournalCorrelatesRequestsAndPlansWithoutCapturingPayloads(): void {
        $registry = $this->createMock(ToolRegistry::class);
        $password = bin2hex(random_bytes(12));
        $plan = bin2hex(random_bytes(32));
        $preview = ['structuredContent' => ['requiresConfirmation' => true, 'plan_state' => $plan]];
        $done = ['structuredContent' => ['url' => 'https://example.invalid/s/private']];
        $registry->method('call')->willReturnOnConsecutiveCalls($preview, $done, ['isError' => true, 'content' => [['text' => SharingMessages::shareRefused()]]]);
        $requests = new ToolRequests(InstalledApp::identity($this), $registry, $this->diagnostics);
        $params = ['name' => 'files_share', 'arguments' => ['path' => '/private', 'custom_password' => $password]];
        self::assertSame($preview, $requests->call('client-secret-id', $params, 'target', false)['body']['result']);
        $params['arguments'] += ['confirm' => true, 'plan_state' => $plan];
        self::assertSame($done, $requests->call(2, $params, 'target', false)['body']['result']);
        $requests->call(3, $params, 'target', true);
        $raw = file_get_contents($this->journal);
        foreach ([$password, $plan, '/private', 'client-secret-id', 'https://', 'target'] as $private) {
            self::assertStringNotContainsString($private, $raw);
        }
        $rows = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($raw)));
        self::assertSame(['received', 'preview_returned', 'received', 'success', 'received', 'share_refused'], array_column($rows, 'event'));
        self::assertSame(['initial','initial','confirmation','confirmation','confirmation','confirmation'], array_column($rows, 'phase'));
        self::assertSame($rows[0]['request'], $rows[1]['request']);
        self::assertNotSame($rows[0]['request'], $rows[2]['request']);
        self::assertSame($rows[1]['plan'], $rows[2]['plan']);
    }

    public function testTraceIsLimitedToConfiguredAccountToolAndTime(): void {
        self::assertNull($this->diagnostics->begin('other', 'files_share', []));
        self::assertNull($this->diagnostics->begin('target', 'mail_search', []));
        $this->settings['until'] = time();
        self::assertNull($this->diagnostics->begin('target', 'files_share', []));
        $this->settings = [];
        self::assertNull($this->diagnostics->begin('target', 'files_share', []));
        self::assertSame('', file_get_contents($this->journal));
    }

    public function testUnavailableJournalPreservesExecutionAndException(): void {
        $registry = $this->createMock(ToolRegistry::class);
        $registry->method('call')->willThrowException(new \RuntimeException('private exception'));
        $requests = new ToolRequests(InstalledApp::identity($this), $registry, $this->diagnostics);
        foreach ([0600, 0644] as $mode) {
            file_put_contents($this->journal, '');
            chmod($this->journal, $mode);
            try {
                $requests->call(1, ['name' => 'files_share'], 'target', false);
                self::fail('The original exception must propagate.');
            } catch (\RuntimeException $e) {
                self::assertSame('private exception', $e->getMessage());
            }
            $raw = file_get_contents($this->journal);
            self::assertStringNotContainsString('private exception', $raw);
            if ($mode === 0600) {
                self::assertStringContainsString('"event":"server_error"', $raw);
            } else {
                self::assertSame('', $raw);
            }
        }
    }
}
