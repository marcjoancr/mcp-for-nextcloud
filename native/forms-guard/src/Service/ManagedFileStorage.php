<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Runs at the pinned core's storage boundary, AFTER View acquires its native exclusive lock. */
final class ManagedFileStorage extends \OC\Files\Storage\Wrapper\Wrapper {
    public bool $enabled=true;
    public function __construct(\OCP\Files\Storage\IStorage $storage,private array $approved) {parent::__construct(['storage'=>$storage]);}
    private function verify(string $path): void {
        if (OriginContext::active()) {OriginContext::assertFresh();}
        $meta=$this->approved[$path]??null;$storage=$this->getWrapperStorage();
        if ($meta===null || (int)$storage->getCache()->getId($path)!==$meta['file_id']) {throw new \RuntimeException('Unapproved native file mutation');}
        $stream=$storage->fopen($path,'rb');if (!is_resource($stream)) {throw new \RuntimeException('Managed file is unavailable');}
        try {$ctx=hash_init('sha256');$size=hash_update_stream($ctx,$stream,AnswerFiles::MAX_BYTES+1);$sha=hash_final($ctx);}finally{if (is_resource($stream)) {fclose($stream);}}
        if ($size!==$meta['size'] || !hash_equals($sha,$meta['sha256'])) {throw new \RuntimeException('Managed file changed before native mutation');}
    }
    public function unlink(string $path): bool {
        if ($this->enabled) {$this->verify($path);}
        return parent::unlink($path);
    }
    public function rename(string $source,string $target): bool {
        if ($this->enabled) {
            $this->verify($source);
            if ($this->getWrapperStorage()->file_exists($target)) {throw new \RuntimeException('Forms file destination already exists');}
        }
        return parent::rename($source,$target);
    }
    public function rmdir(string $path): bool {
        if ($this->enabled) {throw new \RuntimeException('Recursive native folder deletion is not admitted');}
        return parent::rmdir($path);
    }
    public function moveFromStorage(\OCP\Files\Storage\IStorage $sourceStorage,string $sourceInternalPath,string $targetInternalPath): bool {
        if ($this->enabled) {throw new \RuntimeException('Cross-storage native file movement is not qualified');}
        return parent::moveFromStorage($sourceStorage,$sourceInternalPath,$targetInternalPath);
    }
}
