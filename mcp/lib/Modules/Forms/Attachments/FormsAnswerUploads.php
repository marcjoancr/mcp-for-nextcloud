<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Forms\Attachments;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCP\Files\{File, IRootFolder};
use OCP\Lock\ILockingProvider;
/** Actor-relative sources only. The caller opens the current Files scope and checks the Files read grant. */
final class FormsAnswerUploads {
    public const MAX_BYTES = 26214400;
    public function __construct(private IRootFolder $root, private FilesAccessGuard $access) {}
    /** Read-only plan; nothing is staged and no upload token is minted. @return list<array<string,mixed>> */
    public function snapshot(string $uid, array $paths): array {
        if (count($paths)<1 || count($paths)>10) {throw new \InvalidArgumentException('Forms accepts one to ten files');}
        $this->access->clearCache();$this->access->assertScopeReadable();
        $result=[];$bytes=0;
        foreach ($paths as $path) {
            $path=PathGuard::normalize($path);$file=$this->source($uid,$path);
            $meta=$this->metadata($file,$path);$bytes+=$meta['size'];
            if ($bytes>self::MAX_BYTES) {throw new \RuntimeException('Forms transfer exceeds the byte limit');}
            $result[]=$meta;
        }
        if (count(array_unique(array_column($result,'file_id')))!==count($result)) {throw new \InvalidArgumentException('Duplicate Forms file source');}
        return $result;
    }
    /**
     * Fresh scope/exclusion/native ACL check, then shared source locks through native consumption.
     * Callback gets private streams, never root-relative or local storage paths. Streams close on every outcome.
     */
    public function withSources(string $uid, array $approved, callable $consume): mixed {
        if (count($approved)<1 || count($approved)>10) {throw new \InvalidArgumentException('Invalid Forms source manifest');}
        $this->access->clearCache();$this->access->assertScopeReadable();$locked=[];$streams=[];$sources=[];$bytes=0;
        try {
            foreach ($approved as $meta) {
                $file=$this->source($uid,$meta['path']);$file->lock(ILockingProvider::LOCK_SHARED);$locked[]=$file;
                $this->access->clearCache();$this->access->assertVisible($file);
                $actual=$this->metadata($file,$meta['path']);
                if ($actual!==$meta) {throw new \RuntimeException('Forms file source changed after preview');}
                $bytes+=$actual['size'];if ($bytes>self::MAX_BYTES) {throw new \RuntimeException('Forms transfer exceeds the byte limit');}
                $stream=$file->fopen('rb');if (!is_resource($stream)) {throw new \RuntimeException('Forms file source is unavailable');}
                $streams[]=$stream;$sources[]=$actual+['stream'=>$stream];
            }
            return $consume($sources);
        } finally {
            foreach ($streams as $stream) {if (is_resource($stream)) {fclose($stream);}}
            foreach (array_reverse($locked) as $file) {$file->unlock(ILockingProvider::LOCK_SHARED);}
        }
    }
    private function source(string $uid,string $path): File {
        return NodeAccess::requireFile(NodeAccess::get($this->root->getUserFolder($uid),$path,$this->access));
    }
    /** @return array<string,mixed> */
    private function metadata(File $file,string $path): array {
        $size=(int)$file->getSize();$etag=(string)$file->getEtag();
        if ($size<0 || $size>self::MAX_BYTES) {throw new \RuntimeException('Forms transfer exceeds the byte limit');}
        $stream=$file->fopen('rb');if (!is_resource($stream)) {throw new \RuntimeException('Forms file source is unavailable');}
        try {$hash=hash_init('sha256');$read=hash_update_stream($hash,$stream,self::MAX_BYTES+1);$digest=hash_final($hash);}
        finally {fclose($stream);}
        if ($read!==$size || (string)$file->getEtag()!==$etag) {throw new \RuntimeException('Forms file source changed during preview');}
        return ['path'=>$path,'file_id'=>(int)$file->getId(),'name'=>$file->getName(),'size'=>$size,'etag'=>$etag,'mime'=>$file->getMimetype(),'sha256'=>$digest];
    }
}
