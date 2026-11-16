<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;

use OCP\Files\Cache\IScanner;
use OCP\Files\Storage\IStorage;

/** Child-only first-use guard: reads delegate, writes need a durable target-namespace journal claim. */
final class NativeHomeStorage extends \OC\Files\Storage\Wrapper\Wrapper {
    private static bool $guardedInitialization = true;
    /** @var list<self> */
    private static array $instances = [];
    private static ?\RuntimeException $globalFailure = null;
    private ?\RuntimeException $failure = null;

    public function __construct(IStorage $storage, private string $mountPoint, private string $targetUid, private NativeHomeJournal $journal, private \Closure $authority) {
        StorageAdmission::storage($storage);
        parent::__construct(['storage' => $storage]);
        self::$instances[] = $this;
    }

    public function assertHealthy(): void {
        if ($this->failure !== null) { throw $this->failure; }
    }

    /** The child switches to ordinary Forms sinks only after native setup has committed. */
    public static function guardedInitialization(bool $enabled): void { self::$guardedInitialization = $enabled; }
    public static function latchFailure(\Throwable $error): void { self::$globalFailure ??= new \RuntimeException('Native Home storage admission is latched', 0, $error); }
    public static function assertAllHealthy(): void {
        if (self::$globalFailure !== null) { throw self::$globalFailure; }
        foreach (self::$instances as $instance) { $instance->assertHealthy(); }
    }
    public function reject(string $message): never { $this->deny($message); }

    public function getScanner(string $path = '', ?IStorage $storage = null): IScanner {
        $this->assertHealthy();
        $storage ??= $this;
        if (!$this->containsGuard($storage)) { $this->deny('Native Home scanner storage escaped its guard'); }
        return new NativeHomeScanner(parent::getScanner($path, $storage), $this);
    }

    private function containsGuard(IStorage $storage): bool {
        $seen = [];
        while ($storage instanceof \OC\Files\Storage\Wrapper\Wrapper) {
            if ($storage === $this) { return true; }
            $id = spl_object_id($storage);
            if (isset($seen[$id]) || count($seen) >= 32) { return false; }
            $seen[$id] = true;
            $storage = $storage->getWrapperStorage();
        }
        return false;
    }

    public function mkdir(string $path): bool {
        $internal = $this->relative($path);
        $storage = parent::getWrapperStorage();
        if (self::$guardedInitialization && $storage->file_exists($internal)) {
            // Core and native wrappers may idempotently mkdir an already-existing Home root.
            // It is safe only for a real directory, never a file or symlink collision.
            $local = $storage->getLocalFile($internal);
            $stat = is_string($local) ? @lstat($local) : false;
            if (!$storage->is_dir($internal) || !is_array($stat) || is_link($local)
                || ($stat['mode'] & 0170000) !== 0040000) {
                $this->deny('Forms destination already exists');
            }
            ($this->authority)();
            StorageAdmission::storage($storage);
            return false;
        }
        return $this->effect('mkdir', $path, function (string $internal): bool {
            if (self::$guardedInitialization && parent::getWrapperStorage()->file_exists($internal)) {
                throw new \RuntimeException('Forms destination already exists');
            }
            return parent::mkdir($internal);
        });
    }

    public function file_put_contents(string $path, mixed $data): int|float|false {
        return $this->effect('write', $path, function (string $internal) use ($data): int|float|false {
            if (self::$guardedInitialization && parent::getWrapperStorage()->file_exists($internal)) {
                throw new \RuntimeException('Forms destination already exists');
            }
            return parent::file_put_contents($internal, $data);
        });
    }

    public function touch(string $path, ?int $mtime = null): bool {
        return $this->effect('touch', $path, function (string $internal) use ($mtime): bool {
            if (self::$guardedInitialization && parent::getWrapperStorage()->file_exists($internal)) {
                throw new \RuntimeException('Forms destination already exists');
            }
            return parent::touch($internal, $mtime);
        });
    }

    public function rename(string $source, string $target): bool {
        $this->deny('Native Home rename is not admitted');
    }

    public function copy(string $source, string $target): bool {
        $this->deny('Native Home copy is not admitted');
    }

    public function fopen(string $path, string $mode) {
        $this->assertHealthy();
        if (strpbrk($mode, 'waxc+') !== false) {
            if (self::$guardedInitialization) { $this->deny('Unjournaled native Home stream write'); }
            $this->relative($path); ($this->authority)();
        }
        return parent::fopen($path, $mode);
    }

    public function unlink(string $path): bool { $this->deny('Native Home deletion is not admitted'); }
    public function rmdir(string $path): bool { $this->deny('Native Home deletion is not admitted'); }
    public function moveFromStorage(IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool {
        $this->deny('Native Home cross-storage movement is not admitted');
    }

    /** Used by the bounded scanner before it reaches an unmanaged directory walk. */
    public function assertScanPath(string $path): string { return $this->relative($path); }

    public function scan(string $path, bool $recursive, callable $scan): mixed {
        $this->assertHealthy();
        $this->relative($path);
        if (!self::$guardedInitialization) { ($this->authority)(); return $scan($path); }
        $handle = parent::getWrapperStorage()->opendir($path);
        if (!is_resource($handle)) { $this->deny('Native Home scan metadata is unavailable'); }
        $entries = 0;
        try {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') { continue; }
                if (++$entries > 100 || ($recursive && $entries > 0)) { $this->deny('Native Home scan entry bound exceeded'); }
            }
        } finally { closedir($handle); }
        if (!NativeHomePath::scanWithinBound($recursive, $entries)) { $this->deny('Native Home scan entry bound exceeded'); }
        return $this->effect('scan', $path, static fn(string $internal) => $scan($internal));
    }

    public function scanFile(string $path, callable $scan): mixed {
        return $this->effect('scan', $path, static fn(string $internal) => $scan($internal));
    }

    private function effect(string $operation, string $path, callable $work): mixed {
        $this->assertHealthy();
        $relative = $this->relative($path);
        if (!self::$guardedInitialization) {
            ($this->authority)();
            StorageAdmission::storage(parent::getWrapperStorage());
            $result = $work($path);
            if ($result === false && $operation === 'mkdir' && parent::getWrapperStorage()->is_dir($path)) {
                ($this->authority)();
                return false;
            }
            if ($result === false) { throw new \RuntimeException('Native Home storage effect failed'); }
            ($this->authority)();
            return $result;
        }
        $token = null;
        try {
            ($this->authority)();
            StorageAdmission::storage(parent::getWrapperStorage());
            $token = $this->journal->intent($operation, $relative);
            $result = $work($path);
            if ($result === false) { throw new \RuntimeException('Native Home storage effect failed'); }
            ($this->authority)();
            $digest = $operation === 'scan' ? null : $this->effectDigest($path);
            $this->journal->complete($token, $digest);
            return $result;
        } catch (\Throwable $error) {
            if ($token !== null) { try { $this->journal->fail($token); } catch (\Throwable) {} }
            $this->failure ??= new \RuntimeException('Native Home storage admission is latched', 0, $error);
            throw $this->failure;
        }
    }

    private function effectDigest(string $path): string {
        $local = parent::getWrapperStorage()->getLocalFile($path);
        if (!is_string($local) || $local === '' || !str_starts_with($local, '/')) {
            throw new \RuntimeException('Native Home local effect identity is unavailable');
        }
        $stat = @lstat($local);
        if (!is_array($stat) || is_link($local)) { throw new \RuntimeException('Native Home local effect identity is unavailable'); }
        return NativeHomePath::identityDigest($local, ($stat['mode'] & 0170000) === 0100000);
    }

    private function relative(string $path): string {
        if ($path === '') { $internal = ''; }
        else {
            if (str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')
                || str_contains($path, '//') || in_array('.', explode('/', $path), true) || in_array('..', explode('/', $path), true)) {
                $this->deny('Native Home path is unavailable');
            }
            $internal = $path;
        }
        $base = '/' . $this->targetUid;
        $mount = $this->mountPoint === '/' ? '/' : rtrim($this->mountPoint, '/');
        if ($mount !== $base && !str_starts_with($mount, $base . '/')) { $this->deny('Native Home mount escaped its target'); }
        $prefix = $mount === $base ? '' : substr($mount, strlen($base) + 1);
        $relative = $prefix === '' ? $internal : ($internal === '' ? $prefix : $prefix . '/' . $internal);
        if ($relative !== '' && $relative !== 'files' && !str_starts_with($relative, 'files/')) {
            $this->deny('Native Home write escaped Files namespace');
        }
        return $relative;
    }

    private function deny(string $message): never {
        $this->failure ??= new \RuntimeException('Native Home storage admission is latched');
        self::latchFailure($this->failure);
        throw $this->failure;
    }
}
