<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Descriptions;

use OCA\Mcp\Access\BackupPolicy\BackupLocation;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use Psr\Container\NotFoundExceptionInterface;

final class DescriptionService {
    public const KEY = 'mcp-image-description';
    public const MAX_BYTES = 16000;

    public function __construct(
        private IRootFolder $rootFolder,
        private \Closure $resolveMetadata,
        private BackupLocation $backups,
        private EmbeddedDescriptionReader $embedded,
        private NodeAccessInfo $accessInfo,
        private SharedWriteGuard $sharedWrites,
    ) {}

    /** @return array{description:string, source:string, stored:bool, state:string, etag:string, supported_fields:list<string>} */
    public function read(string $userId, string $path, FilesAccessGuard $visibility): array {
        [, $file] = $this->resolveFile($userId, $path, $visibility);
        return $this->readFile($file, $userId);
    }

    /** @return array<string, mixed> */
    public function preview(string $userId, string $path, string $description, FilesAccessGuard $visibility): array {
        $this->assertDescription($description);
        [, $file] = $this->resolveFile($userId, $path, $visibility);
        if (!$file->isUpdateable()) throw new ToolFailure(Translator::t('This file cannot be updated.'));
        $current = $this->readFile($file, $userId);
        return $current + ['shared' => $this->sharedWrites->guard($file, $userId, PathGuard::normalize($path), false)];
    }

    /** @return array<string, mixed> */
    public function readFile(File $file, ?string $userId = null, ?IFilesMetadata $snapshot = null): array {
        [$stored, $photos, $syncToken] = $this->metadataDescriptions($file, $snapshot);
        $rawExif = $photos === null ? $this->embedded->read($file) : null;
        $hasStored = $stored !== null;
        $embedded = $photos ?? $rawExif;
        $description = $hasStored ? $stored : ($embedded ?? '');
        $source = $hasStored ? 'files_metadata' : ($photos !== null ? 'photos_metadata' : ($rawExif !== null ? 'embedded_exif' : 'none'));
        $state = hash('sha256', json_encode([(string)$file->getId(), $syncToken, $hasStored, $stored, $embedded, (string)$file->getEtag()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        return [
            'description' => $description,
            'source' => $source,
            'stored' => $hasStored,
            'state' => $state,
            'etag' => (string)$file->getEtag(),
            'supported_fields' => ['Files metadata mcp-image-description', 'Photos metadata photos-ifd0 ImageDescription', 'EXIF IFD0 ImageDescription in JPEG files'],
            'access' => $userId === null ? null : $this->accessInfo->describe($file, $userId),
        ];
    }

    /** @return array<string, mixed> */
    public function write(string $userId, string $path, string $description, string $expectedState, FilesAccessGuard $visibility, bool $confirmShared = false): array {
        return $this->save($userId, $path, $description, $expectedState, $visibility, $confirmShared);
    }

    /** @return array<string, mixed> */
    private function save(string $userId, string $path, string $description, string $expectedState, FilesAccessGuard $visibility, bool $confirmShared): array {
        $path = PathGuard::normalize($path);
        $this->assertAllowedPath($path, false);
        $this->assertDescription($description);
        [, $file] = $this->resolveFile($userId, $path, $visibility);
        if (!$file->isUpdateable()) throw new ToolFailure(Translator::t('This file cannot be updated.'));
        try {
            $manager = ($this->resolveMetadata)();
        } catch (\Throwable) {
            throw new ToolFailure(Translator::t('Files metadata storage is unavailable.'));
        }
        if (!$manager instanceof IFilesMetadataManager) throw new ToolFailure(Translator::t('Files metadata storage is unavailable.'));
        try {
            $metadata = $manager->getMetadata((int)$file->getId(), true);
        } catch (\Throwable) {
            throw new ToolFailure(Translator::t('Files metadata could not be read.'));
        }
        $current = $this->readFile($file, $userId, $metadata);
        if (!hash_equals($current['state'], $expectedState)) throw new ToolFailure(Translator::t('The image description changed; preview the update again.'));
        if (($shared = $this->sharedWrites->guard($file, $userId, $path, $confirmShared)) !== null) return ['shared_confirmation' => $shared];
        try {
            // Saving this exact metadata snapshot preserves Nextcloud's sync token. A concurrent update is refused.
            $metadata->setString(self::KEY, $description);
            $manager->saveMetadata($metadata);
            $saved = $manager->getMetadata((int)$file->getId(), false);
            if (!$saved->hasKey(self::KEY) || $saved->getString(self::KEY) !== $description) {
                throw new \RuntimeException('Metadata readback mismatch');
            }
        } catch (\Throwable) {
            throw new ToolFailure(Translator::t('The image description could not be saved.'));
        }
        return $this->readFile($file, $userId);
    }

    /** @return array{Folder, File} */
    private function resolveFile(string $userId, string $path, FilesAccessGuard $visibility): array {
        $path = PathGuard::normalize($path);
        $this->assertAllowedPath($path, true);
        $root = $this->rootFolder->getUserFolder($userId);
        $file = NodeAccess::requireFile(NodeAccess::get($root, $path, $visibility));
        if (!str_starts_with((string)$file->getMimetype(), 'image/')) {
            throw new ToolFailure(Translator::t('This file is not an image.'));
        }
        return [$root, $file];
    }

    private function assertDescription(string $description): void {
        if (!mb_check_encoding($description, 'UTF-8') || strlen($description) > self::MAX_BYTES || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $description)) {
            throw new \InvalidArgumentException(Translator::t('Description must be plain text of at most 16000 UTF-8 bytes.'));
        }
    }

    private function assertAllowedPath(string $path, bool $reading): void {
        if ($this->backups->contains($path)) {
            throw new ToolFailure($reading ? Translator::t('This protected Files location cannot be read.') : Translator::t('This protected Files location cannot be edited.'));
        }
    }

    /** @return array{0:?string,1:?string,2:string} */
    private function metadataDescriptions(File $file, ?IFilesMetadata $snapshot = null): array {
        if ($snapshot === null) {
            try {
                $manager = ($this->resolveMetadata)();
            } catch (NotFoundExceptionInterface) {
                return [null, null, ''];
            } catch (\Throwable) {
                throw new ToolFailure(Translator::t('Files metadata storage is unavailable.'));
            }
            if (!$manager instanceof IFilesMetadataManager) return [null, null, ''];
            try {
                $snapshot = $manager->getMetadata((int)$file->getId(), false);
            } catch (FilesMetadataNotFoundException) {
                return [null, null, ''];
            } catch (\Throwable) {
                throw new ToolFailure(Translator::t('Files metadata could not be read.'));
            }
        }
        try {
            $stored = $snapshot->hasKey(self::KEY) ? $snapshot->getString(self::KEY) : null;
            $photos = null;
            if ($snapshot->hasKey('photos-ifd0')) {
                $ifd0 = $snapshot->getArray('photos-ifd0');
                foreach (['ImageDescription', 'image_description', 'Image Description'] as $key) {
                    if (isset($ifd0[$key]) && is_string($ifd0[$key]) && $ifd0[$key] !== '') {
                        $photos = $ifd0[$key];
                        break;
                    }
                }
            }
            return [$stored, $photos, $snapshot->getSyncToken()];
        } catch (\Throwable) {
            throw new ToolFailure(Translator::t('Files metadata could not be read.'));
        }
    }
}
