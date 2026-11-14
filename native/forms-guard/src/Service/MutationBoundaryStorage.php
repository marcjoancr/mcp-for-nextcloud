<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;

use OCP\Files\Storage\IStorage;

/** Checks trusted authority at the storage call reached after View acquires its write lock. */
final class MutationBoundaryStorage extends \OC\Files\Storage\Wrapper\Wrapper {
    public bool $enabled = true;
    private ?\RuntimeException $failure = null;

    public function __construct(
        IStorage $storage,
        private string $target,
        private ?int $expectedId,
        private ?string $expectedEtag,
        private \Closure $authorize,
    ) {
        StorageAdmission::storage($storage);
        parent::__construct(['storage' => $storage]);
    }

    private function admit(string $path): void {
        if (!$this->enabled || $path !== $this->target) {
            return;
        }
        $this->assertAdmitted();
        try {
            $storage = $this->getWrapperStorage();
            StorageAdmission::storage($storage);
            if ($this->expectedId === null) {
                if ($storage->file_exists($path)) {
                    throw new \RuntimeException('Forms destination already exists');
                }
            } else {
                $current = $storage->getCache()->get($path);
                if (!$storage->file_exists($path) || $current === false
                    || (int)$current['fileid'] !== $this->expectedId
                    || (string)$current['etag'] !== $this->expectedEtag) {
                    throw new \RuntimeException('Forms destination identity or content changed');
                }
            }
            $parent = dirname($path);
            if ($parent === '.') {
                $parent = '';
            }
            $writable = $this->expectedId === null ? $storage->isCreatable($parent) : $storage->isUpdatable($path);
            if (!$writable) {
                throw new \RuntimeException('Native Forms destination permission changed');
            }
            ($this->authorize)('content');
        } catch (\Throwable $error) {
            // The caller may already have created an empty file or a backup in an earlier stage.
            $this->failure ??= new \RuntimeException('Forms file mutation admission failed', 0, $error);
            throw $this->failure;
        }
    }

    /** Native View::touch can swallow an admission exception while emulating metadata updates. */
    public function assertAdmitted(): void {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    public function touch(string $path, ?int $mtime = null): bool {
        if ($this->expectedId === null) {
            $this->admit($path);
        }
        return parent::touch($path, $mtime);
    }

    public function mkdir(string $path): bool {
        $this->admit($path);
        return parent::mkdir($path);
    }

    public function file_put_contents(string $path, mixed $data): int|float|false {
        $this->admit($path);
        return parent::file_put_contents($path, $data);
    }

    public function fopen(string $path, string $mode) {
        if (strpbrk($mode, 'waxc+') !== false) {
            $this->admit($path);
        }
        return parent::fopen($path, $mode);
    }
}
