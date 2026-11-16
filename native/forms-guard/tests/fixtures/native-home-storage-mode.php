<?php
declare(strict_types=1);

namespace OCP\Files\Storage { interface IStorage {} }
namespace OCP\Files\Cache { interface IScanner {} }
namespace OC\Files\Storage\Wrapper {
    class Wrapper implements \OCP\Files\Storage\IStorage {
        public ?\OCP\Files\Cache\IScanner $scanner = null;
        private \OCP\Files\Storage\IStorage $storage;
        public function __construct(array $arguments) { $this->storage = $arguments['storage']; }
        public function getWrapperStorage(): \OCP\Files\Storage\IStorage { return $this->storage; }
        public function getScanner(string $path = '', ?\OCP\Files\Storage\IStorage $storage = null): \OCP\Files\Cache\IScanner {
            return $this->scanner ??= new \NativeHomeStorageFixtureScanner();
        }
        public function file_exists(string $path): bool { return $this->storage->file_exists($path); }
        public function is_dir(string $path): bool { return $this->storage->is_dir($path); }
        public function mkdir(string $path): bool { return $this->storage->mkdir($path); }
        public function file_put_contents(string $path, mixed $data): int|float|false { return $this->storage->file_put_contents($path, $data); }
        public function touch(string $path, ?int $mtime = null): bool { return $this->storage->touch($path, $mtime); }
    }
}
namespace OCA\Forms\Mcp\Service {
    final class StorageAdmission { public static function storage(object $storage): void {} }
    final class NativeHomeJournal {
        public array $calls = [];
        public function intent(string $operation, string $path, ?string $sha256 = null): int { $this->calls[]=['intent',$operation,$path]; return count($this->calls); }
        public function complete(int $token, ?string $sha256 = null): void { $this->calls[]=['complete',$token,$sha256]; }
        public function fail(int $token): void { $this->calls[]=['fail',$token]; }
    }
}
namespace {
    final class NativeHomeStorageFixtureScanner implements \OCP\Files\Cache\IScanner {}
    final class NativeHomeStorageFixture implements \OCP\Files\Storage\IStorage {
        public function __construct(public string $root) {}
        public function file_exists(string $path): bool { return file_exists($this->getLocalFile($path)); }
        public function is_dir(string $path): bool { return is_dir($this->getLocalFile($path)); }
        public function mkdir(string $path): bool { return @mkdir($this->getLocalFile($path)); }
        public function file_put_contents(string $path, mixed $data): int|float|false { return file_put_contents($this->getLocalFile($path), $data); }
        public function touch(string $path, ?int $mtime = null): bool { return touch($this->getLocalFile($path), $mtime ?? time()); }
        public function getLocalFile(string $path): string { return $this->root . '/' . ltrim($path, '/'); }
    }

    require $argv[1];
    require $argv[2];
    require $argv[3];
    $root = sys_get_temp_dir() . '/native-home-storage-mode-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root . '/files', 0700);
    file_put_contents($root . '/files/known.txt', 'before');
    $raw = new NativeHomeStorageFixture($root);
    $journal = new \OCA\Forms\Mcp\Service\NativeHomeJournal();
    $authorizations = 0;
    $storage = new \OCA\Forms\Mcp\Service\NativeHomeStorage($raw, '/recipient', 'recipient', $journal, static function () use (&$authorizations): void { $authorizations++; });
    $outer = new \OC\Files\Storage\Wrapper\Wrapper(['storage'=>$storage]);

    if (!$storage->getScanner('files', $outer) instanceof \OCA\Forms\Mcp\Service\NativeHomeScanner) {
        throw new \RuntimeException('Guarded outer scanner chain was not preserved');
    }

    \OCA\Forms\Mcp\Service\NativeHomeStorage::guardedInitialization(false);
    $idempotentMkdir = $storage->mkdir('files');
    $delegatedWrite = $storage->file_put_contents('files/known.txt', 'after');
    if ($idempotentMkdir !== false || $delegatedWrite !== 5 || file_get_contents($root . '/files/known.txt') !== 'after'
        || $authorizations < 3 || $journal->calls !== []) {
        throw new \RuntimeException('Ordinary Forms sink delegation changed');
    }

    \OCA\Forms\Mcp\Service\NativeHomeStorage::guardedInitialization(true);
    $existingCollisionDenied = false;
    try { $storage->file_put_contents('files/known.txt', 'collision'); }
    catch (\RuntimeException) { $existingCollisionDenied = true; }
    if (!$existingCollisionDenied || file_get_contents($root . '/files/known.txt') !== 'after') {
        throw new \RuntimeException('Guarded setup accepted an existing file collision');
    }

    echo json_encode(['scanner_outer_chain_preserved'=>true,'ordinary_mkdir_noop'=>true,
        'ordinary_write_delegated'=>true,'guarded_collision_denied'=>true], JSON_THROW_ON_ERROR);
}
