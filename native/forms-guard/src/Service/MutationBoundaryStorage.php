<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;

use OCP\Files\Storage\IStorage;

/** Checks trusted authority at the storage call reached after View acquires its write lock. */
final class MutationBoundaryStorage extends \OC\Files\Storage\Wrapper\Wrapper {
    public bool $enabled = true;

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
            throw new \RuntimeException('Forms file mutation admission failed', 0, $error);
        }
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
