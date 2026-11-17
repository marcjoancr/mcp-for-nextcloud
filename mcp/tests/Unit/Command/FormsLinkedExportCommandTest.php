<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Command;

/** Test-local function hooks capture the command alarm without installing a real signal. */
function pcntl_async_signals(bool $enable): bool {
    if ($GLOBALS['formsLinkedCommandTestActive'] ?? false) { return true; }
    return \pcntl_async_signals($enable);
}
function pcntl_signal(int $signal, callable|int $handler, bool $restart_syscalls = true): bool {
    if ($GLOBALS['formsLinkedCommandTestActive'] ?? false) { return true; }
    return \pcntl_signal($signal, $handler, $restart_syscalls);
}
function pcntl_alarm(int $seconds): int {
    if ($GLOBALS['formsLinkedCommandTestActive'] ?? false) {
        $GLOBALS['formsLinkedCommandAlarmCalls'][] = $seconds;
        return 0;
    }
    return \pcntl_alarm($seconds);
}
function stream_get_contents($stream, ?int $length = null, int $offset = -1): string|false {
    if ($GLOBALS['formsLinkedCommandTestActive'] ?? false) {
        return json_encode(['association_id' => str_repeat('a', 64)], JSON_THROW_ON_ERROR);
    }
    return \stream_get_contents($stream, $length, $offset);
}

namespace OCA\Mcp\Tests\Unit\Command;

use OCA\Mcp\Command\FormsLinkedExport;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedExportRunner;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedExportWorker;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class FormsLinkedExportCommandTest extends TestCase {
    protected function tearDown(): void {
        $GLOBALS['formsLinkedCommandTestActive'] = false;
        $GLOBALS['formsLinkedCommandAlarmCalls'] = [];
    }

    public function testAlarmMatchesTheFormsRunnerBudget(): void {
        if (!function_exists('pcntl_alarm') || !function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl is required for the bounded Forms worker command.');
        }
        $GLOBALS['formsLinkedCommandTestActive'] = true;
        $GLOBALS['formsLinkedCommandAlarmCalls'] = [];

        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn(null);
        $worker = new FormsLinkedExportWorker($session, static fn(): array => ['state' => 'collecting'], 'cli');
        $tester = new CommandTester(new FormsLinkedExport($worker));

        $tester->execute([]);

        self::assertSame(['{"status":"completed"}'.PHP_EOL], [$tester->getDisplay()]);
        self::assertSame([(int)ceil(FormsLinkedExportRunner::CHILD_BUDGET_MS / 1000), 0],
            $GLOBALS['formsLinkedCommandAlarmCalls']);
    }
}
