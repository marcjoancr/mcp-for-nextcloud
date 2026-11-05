<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Diagnostics;

use Closure;
use OCP\IConfig;

/** Operator-only, time-limited tracing. Neither request data nor exception text enters the journal. */
final class ToolCallDiagnostics {
    /** @param Closure(string):string $classifyToolError Maps safe tool messages to fixed operator codes. */
    public function __construct(private IConfig $config, private Closure $classifyToolError) {}

    /** @return array{file:string, request:string, tool:string, phase:string, plan?:string}|null */
    public function begin(string $uid, mixed $name, mixed $arguments): ?array {
        try {
            $settings = $this->config->getSystemValue('mcp.tool_diagnostics', []);
            if (!is_array($settings) || ($settings['user'] ?? null) !== $uid
                || !is_int($settings['until'] ?? null) || $settings['until'] <= time()
                || !is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $name)
                || !is_array($settings['tools'] ?? null) || !in_array($name, $settings['tools'], true)) {
                return null;
            }
            $file = $settings['file'] ?? null;
            if (!is_string($file) || !str_starts_with($file, '/') || is_link($file) || !is_file($file)) {
                return null;
            }
            $confirm = is_array($arguments) ? ($arguments['confirm'] ?? false) : false;
            $trace = ['file' => $file, 'request' => bin2hex(random_bytes(8)), 'tool' => $name,
                'phase' => match ($confirm) { true => 'confirmation', false => 'initial', default => 'invalid_confirmation' }];
            if ($confirm === true && is_array($arguments)) {
                $this->addPlanReference($trace, $arguments['plan_state'] ?? null);
            }
            $this->record($trace, 'received');
            return $trace;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{file:string, request:string, tool:string, phase:string, plan?:string} $trace
     * @param array<string,mixed> $reply protocol envelope; only fixed classifications may be written
     */
    public function finish(array $trace, array $reply): void {
        try {
            $result = $reply['result'] ?? [];
            if (isset($reply['error'])) {
                $outcome = match (true) {
                    ($reply['error']['message'] ?? null) === 'Unknown tool' => 'tool_unavailable',
                    ($reply['error']['data']['field'] ?? null) === 'plan_state' => 'invalid_plan_state',
                    ($reply['error']['code'] ?? null) === -32602 => 'invalid_arguments',
                    default => 'protocol_error',
                };
            } elseif (($result['isError'] ?? false) === true) {
                $outcome = ($this->classifyToolError)((string)($result['content'][0]['text'] ?? ''));
            } elseif (($result['structuredContent']['requiresConfirmation'] ?? false) === true) {
                $this->addPlanReference($trace, $result['structuredContent']['plan_state'] ?? null);
                $outcome = $trace['phase'] === 'confirmation' ? 'new_preview_required' : 'preview_returned';
            } else {
                $outcome = 'success';
            }
            $this->record($trace, $outcome);
        } catch (\Throwable) {
            // Diagnostics must not change an operation that has already executed.
        }
    }

    /** @param array{file:string, request:string, tool:string, phase:string, plan?:string} $trace */
    public function failed(array $trace): void {
        $this->record($trace, 'server_error');
    }

    /** @param array{file:string, request:string, tool:string, phase:string, plan?:string} $trace */
    private function addPlanReference(array &$trace, mixed $token): void {
        unset($trace['plan']);
        if (is_string($token) && preg_match('/^[0-9a-f]{64}$/D', $token)) {
            $secret = $this->config->getSystemValueString('secret', '');
            if ($secret !== '') {
                $trace['plan'] = substr(hash_hmac('sha256', 'mcp-diagnostics|' . $token, $secret), 0, 24);
            }
        }
    }

    /** @param array{file:string, request:string, tool:string, phase:string, plan?:string} $trace */
    private function record(array $trace, string $event): void {
        $stream = null;
        try {
            // Open an existing, operator-provisioned file only. The parent directory is owned by the operator.
            $stream = @fopen($trace['file'], 'r+b');
            if ($stream === false || !flock($stream, LOCK_EX | LOCK_NB)) {
                return;
            }
            $stat = fstat($stream);
            if ($stat === false || ($stat['mode'] & 0170077) !== 0100000 || $stat['size'] > 1048576 - 512) {
                return;
            }
            unset($trace['file']);
            if (fseek($stream, 0, SEEK_END) === 0) {
                @fwrite($stream, json_encode(['utc' => gmdate('Y-m-d\TH:i:s\Z'), 'event' => $event] + $trace, JSON_THROW_ON_ERROR) . "\n");
            }
        } catch (\Throwable) {
            // Full, rotated or inaccessible journals must never affect the caller.
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
