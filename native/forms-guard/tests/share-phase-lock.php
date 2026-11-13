<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
final class ShareAfterLock implements \OCP\Lock\ILockingProvider
{
    public bool $changed = false;
    public bool $completed = false;
    public function __construct(private \OCP\Lock\ILockingProvider $inner, private string $key, private Closure $change)
    {
    }
    public function isLocked(string $path, int $type): bool
    {
        return $this->inner->isLocked($path, $type);
    }
    public function acquireLock(string $path, int $type, ?string $readablePath = null): void
    {
        $this->inner->acquireLock($path, $type, $readablePath);
        $this->after($path, $type);
    }
    public function changeLock(string $path, int $targetType): void
    {
        $this->inner->changeLock($path, $targetType);
        $this->after($path, $targetType);
    }
    private function after(string $path, int $type): void
    {
        if (!$this->changed && $path === $this->key && $type === self::LOCK_EXCLUSIVE) {
            if (!$this->inner->isLocked($path, $type)) {
                throw new RuntimeException('Real exclusive lock required');
            }
            $this->changed = true;
            $result = ($this->change)();
            if ($result === false) {
                $this->changed = false;
            } else {
                $this->completed = true;
            }
        }
    }
    public function releaseLock(string $path, int $type): void
    {
        $this->inner->releaseLock($path, $type);
    }
    public function releaseAll(): void
    {
        $this->inner->releaseAll();
    }
}
function shareAuthority(string $uid, string $mode, bool $allow): void
{
    $process = proc_open([PHP_BINARY, __DIR__ . '/share-phase-writer.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(compact('uid', 'mode', 'allow') + ($GLOBALS['share_exclusion'] ?? []) + ($GLOBALS['share_policy_fixture'] ?? [])));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || trim($out) !== 'authority committed') {
        throw new RuntimeException('Independent authority writer failed ' . $err);
    }
}
