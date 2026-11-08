<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Files;

use OCA\Mcp\Access\BackupPolicy\BackupLocation;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\ISharedStorage;

/** Snapshot readable Files bytes into Mail's own attachment storage, never into a public share. */
class MailFilesSource {
    public const MAX_FILES = 10;
    public const MAX_BYTES = 26214400;
    public function __construct(private IRootFolder $roots, private FilesAccessGuard $guard,
        private BackupLocation $backups, private MailServices $services, private \Closure $allowsTool,
        private ?OperationJournal $journal = null) {}

    public function preview(string $uid, array $paths): array {
        $this->authorize($uid);
        if (count($paths) > self::MAX_FILES || count(array_unique($paths)) !== count($paths)) {
            throw new \InvalidArgumentException('Choose at most 10 distinct Files attachments.');
        }
        $manifest = []; $total = 0;
        foreach ($paths as $path) {
            [$file, $bytes] = $this->read($uid, $path, self::MAX_BYTES - $total);
            $total += strlen($bytes);
            $manifest[] = ['path' => PathGuard::normalize($path), 'file_id' => (int)$file->getId(),
                'etag' => $file->getEtag(), 'name' => $file->getName(), 'mime_type' => $file->getMimetype(),
                'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        return $manifest;
    }

    public function stage(string $uid, array $manifest, array $reusable = [], ?int $draftId = null): array {
        $fresh = $this->preview($uid, array_column($manifest, 'path'));
        if ($fresh !== $manifest) throw new ToolFailure('Files attachments changed; preview the draft again.');
        $native = $this->services->attachmentService($uid);
        $staged = []; $resources = [];
        foreach ($manifest as $item) {
            [$source, $bytes] = $this->read($uid, $item['path'], self::MAX_BYTES);
            if ((int)$source->getId() !== $item['file_id'] || $source->getEtag() !== $item['etag']
                || $source->getName() !== $item['name'] || $source->getMimetype() !== $item['mime_type']) {
                throw new ToolFailure('Files attachment identity changed.');
            }
            if (!hash_equals($item['sha256'], hash('sha256', $bytes))) throw new ToolFailure('Files attachment changed.');
            $id = null;
            foreach ($reusable as $old) {
                if ($old['file_id'] === $item['file_id'] && $old['sha256'] === $item['sha256']) {
                    $id = (int)$old['attachment_id']; break;
                }
            }
            if ($id === null) {
                $attachment = $native->addFileFromString($uid, $item['name'], $item['mime_type'], $bytes, null, 'attachment');
                $id = (int)$attachment->getId();
            }
            $resources[] = ['attachment_id' => $id, 'file_id' => $item['file_id'], 'sha256' => $item['sha256']];
            $this->journal?->checkpoint(['staged' => $resources]);
            [$stored, $copy] = $native->getAttachment($uid, $id);
            if (($stored->getLocalMessageId() !== null && $stored->getLocalMessageId() !== $draftId)
                || $stored->getFileName() !== $item['name'] || $stored->getMimeType() !== $item['mime_type']) {
                throw new ToolFailure('The stored Mail attachment identity changed.');
            }
            if (!hash_equals($item['sha256'], hash('sha256', $copy->getContent()))) {
                throw new ToolFailure('The stored Mail attachment could not be verified.');
            }
            $staged[] = ['type' => 'local', 'id' => $id];
        }
        return $staged;
    }

    public function authorize(string $uid): void {
        if (!($this->allowsTool)($uid, 'files_read') || !($this->allowsTool)($uid, 'mail_attach_files')) {
            throw new ToolFailure('Attaching Files content is not permitted for this connection.');
        }
        $this->guard->assertScopeReadable();
    }

    private function read(string $uid, string $path, int $budget): array {
        $path = PathGuard::normalize($path);
        if ($this->backups->contains($path)) throw new ToolFailure('This Files location is unavailable.');
        $file = NodeAccess::run(fn(): File => NodeAccess::requireFile(NodeAccess::get($this->roots->getUserFolder($uid), $path, $this->guard)));
        $storage = $file->getStorage();
        if ($storage->instanceOfStorage(ISharedStorage::class)) {
            // Native storage wrappers forward getShare; refuse if that download policy cannot be inspected.
            try {
                /** @var ISharedStorage $shared */
                $shared = $storage;
                if ($shared->getShare()->getAttributes()?->getAttribute('permissions', 'download') === false) {
                    throw new \RuntimeException('No download');
                }
            } catch (\Throwable) { throw new ToolFailure('This file cannot be downloaded as an attachment.'); }
        }
        $size = $file->getSize();
        if (!is_numeric($size) || $size < 0 || $size > $budget) throw new ToolFailure('Mail attachment size limit exceeded.');
        $stream = $file->fopen('rb');
        if (!is_resource($stream)) throw new ToolFailure('The attachment could not be read.');
        try {
            $bytes = stream_get_contents($stream, $budget + 1);
            if (!is_string($bytes) || strlen($bytes) > $budget || !feof($stream)) throw new ToolFailure('Mail attachment size limit exceeded.');
        } finally { fclose($stream); }
        return [$file, $bytes];
    }
}
