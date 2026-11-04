<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files\Descriptions;

use OCA\Mcp\Access\BackupPolicy\BackupLocation;
use OCA\Mcp\Access\BackupPolicy\BackupSettingsDocument;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Descriptions\DescriptionService;
use OCA\Mcp\Modules\Files\Descriptions\EmbeddedDescriptionReader;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use PHPUnit\Framework\TestCase;

final class DescriptionServiceTest extends TestCase {
    public function testExplicitEmptyMetadataOverridesEmbeddedExifDescription(): void {
        $file = $this->file($this->jpegWithDescription('embedded caption'));
        $metadata = $this->metadata(true, '', []);
        $manager = $this->manager($metadata);
        $service = $this->service($manager);

        $record = $service->readFile($file);

        self::assertSame('', $record['description']);
        self::assertSame('files_metadata', $record['source']);
        self::assertTrue($record['stored']);
    }

    public function testEmbeddedJpegDescriptionIsReadFromBoundedPrefixOfLargeImages(): void {
        $bytes = $this->jpegWithDescription('camera caption') . str_repeat('x', EmbeddedDescriptionReader::MAX_BYTES);
        $metadata = $this->metadata(false, null, []);
        $service = $this->service($this->manager($metadata));

        $record = $service->readFile($this->file($bytes));

        self::assertSame('camera caption', $record['description']);
        self::assertSame('embedded_exif', $record['source']);
    }

    public function testConfirmationStateIncludesResolvedFileIdentity(): void {
        $metadata = $this->metadata(false, null, []);
        $service = $this->service($this->manager($metadata));

        $first = $service->readFile($this->file('first file bytes', 23));
        $second = $service->readFile($this->file('first file bytes', 24));

        self::assertNotSame($first['state'], $second['state']);
    }

    public function testPhotosIfd0MetadataIsUsedAsEmbeddedFallback(): void {
        $file = $this->file('not a JPEG');
        $metadata = $this->metadata(false, null, ['ImageDescription' => 'Photos caption']);
        $service = $this->service($this->manager($metadata));

        $record = $service->readFile($file);

        self::assertSame('Photos caption', $record['description']);
        self::assertSame('photos_metadata', $record['source']);
        self::assertFalse($record['stored']);
    }

    public function testScopedReadResolvesOnlyTheAuthenticatedUsersRoot(): void {
        $file = $this->file('unchanged bytes');
        $root = $this->createMock(Folder::class);
        $root->expects(self::once())->method('get')->with('Photos/image.jpg')->willReturn($file);
        $roots = $this->createMock(IRootFolder::class);
        $roots->expects(self::once())->method('getUserFolder')->with('alice')->willReturn($root);
        $visibility = $this->createMock(FilesAccessGuard::class);
        $visibility->expects(self::once())->method('assertVisible')->with($file)->willThrowException(new ToolFailure('The file is unavailable.'));
        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->expects(self::never())->method('getMetadata');
        $service = $this->scopedService($manager, $roots, $visibility);

        try {
            $service->read('alice', '/Photos/image.jpg', $visibility);
            self::fail('A file excluded by the Files visibility guard must be refused.');
        } catch (ToolFailure $failure) {
            self::assertSame('The file is unavailable.', $failure->getMessage());
        }
    }

    public function testMetadataWriteUsesNativeStoreWithoutRewritingImageBytes(): void {
        $file = $this->file('unchanged bytes');
        $file->expects(self::never())->method('putContent');
        $initial = $this->storedMetadata('old caption', 'sync-1');
        $writeSnapshot = $this->storedMetadata('old caption', 'sync-1');
        $persisted = $this->storedMetadata('new caption', 'sync-2');
        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->method('getMetadata')->willReturnOnConsecutiveCalls($initial, $writeSnapshot, $persisted, $persisted);
        $manager->expects(self::once())->method('saveMetadata')->with($writeSnapshot);
        $root = $this->folderContaining($file, 'Photos/image.jpg');
        $roots = $this->createMock(IRootFolder::class);
        $roots->method('getUserFolder')->with('alice')->willReturn($root);
        $visibility = $this->createMock(FilesAccessGuard::class);
        $visibility->method('assertVisible')->with($file);
        $info = $this->createMock(NodeAccessInfo::class);
        $info->method('describe')->willReturn(['scope' => 'personal', 'permissions' => ['update' => true]]);
        $shared = $this->createMock(SharedWriteGuard::class);
        $shared->method('guard')->willReturn(null);
        $service = $this->scopedService($manager, $roots, $visibility, $info, $shared);
        $preview = $service->read('alice', '/Photos/image.jpg', $visibility);

        $saved = $service->write('alice', '/Photos/image.jpg', 'new caption', $preview['state'], $visibility);

        self::assertSame('new caption', $saved['description']);
        self::assertSame('files_metadata', $saved['source']);
    }

    public function testNativeReadOnlyFileCannotBeEditedEvenWithAConfirmedPlan(): void {
        $file = $this->file('unchanged bytes', 23, false);
        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->expects(self::never())->method('getMetadata');
        $root = $this->folderContaining($file, 'Photos/image.jpg');
        $roots = $this->createMock(IRootFolder::class);
        $roots->method('getUserFolder')->willReturn($root);
        $visibility = $this->createMock(FilesAccessGuard::class);
        $visibility->method('assertVisible')->with($file);
        $service = $this->scopedService($manager, $roots, $visibility);

        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage('cannot be updated');
        $service->write('alice', '/Photos/image.jpg', 'caption', 'confirmed-state', $visibility);
    }

    public function testStaleMetadataSnapshotIsRefusedBeforeNativeSave(): void {
        $file = $this->file('unchanged bytes');
        $initial = $this->storedMetadata('old caption', 'sync-1');
        $staleAtWrite = $this->storedMetadata('newer caption', 'sync-2');
        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->method('getMetadata')->willReturnOnConsecutiveCalls($initial, $staleAtWrite);
        $manager->expects(self::never())->method('saveMetadata');
        $root = $this->folderContaining($file, 'Photos/image.jpg');
        $roots = $this->createMock(IRootFolder::class);
        $roots->method('getUserFolder')->willReturn($root);
        $visibility = $this->createMock(FilesAccessGuard::class);
        $visibility->method('assertVisible')->with($file);
        $info = $this->createMock(NodeAccessInfo::class);
        $info->method('describe')->willReturn(['scope' => 'personal', 'permissions' => ['update' => true]]);
        $service = $this->scopedService($manager, $roots, $visibility, $info, $this->createMock(SharedWriteGuard::class));
        $preview = $service->read('alice', '/Photos/image.jpg', $visibility);

        try {
            $service->write('alice', '/Photos/image.jpg', 'confirmed caption', $preview['state'], $visibility);
            self::fail('A newer metadata sync token must invalidate the confirmed plan.');
        } catch (ToolFailure $failure) {
            self::assertStringContainsString('changed', $failure->getMessage());
        }
    }

    public function testSilentNativeSaveFailureIsDetectedByReadback(): void {
        $file = $this->file('unchanged bytes');
        $initial = $this->storedMetadata('old caption', 'sync-1');
        $writeSnapshot = $this->storedMetadata('old caption', 'sync-1');
        $unchanged = $this->storedMetadata('old caption', 'sync-1');
        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->method('getMetadata')->willReturnOnConsecutiveCalls($initial, $writeSnapshot, $unchanged);
        $root = $this->folderContaining($file, 'Photos/image.jpg');
        $roots = $this->createMock(IRootFolder::class);
        $roots->method('getUserFolder')->willReturn($root);
        $visibility = $this->createMock(FilesAccessGuard::class);
        $visibility->method('assertVisible')->with($file);
        $info = $this->createMock(NodeAccessInfo::class);
        $info->method('describe')->willReturn(['scope' => 'personal', 'permissions' => ['update' => true]]);
        $shared = $this->createMock(SharedWriteGuard::class);
        $shared->method('guard')->willReturn(null);
        $service = $this->scopedService($manager, $roots, $visibility, $info, $shared);
        $preview = $service->read('alice', '/Photos/image.jpg', $visibility);

        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage('could not be saved');
        $service->write('alice', '/Photos/image.jpg', 'new caption', $preview['state'], $visibility);
    }

    private function scopedService(IFilesMetadataManager $manager, IRootFolder $root, FilesAccessGuard $visibility, ?NodeAccessInfo $info = null, ?SharedWriteGuard $shared = null): DescriptionService {
        return new DescriptionService(
            $root,
            static fn () => $manager,
            $this->backupLocation(),
            new EmbeddedDescriptionReader(),
            $info ?? $this->createMock(NodeAccessInfo::class),
            $shared ?? $this->createMock(SharedWriteGuard::class),
        );
    }

    private function backupLocation(): BackupLocation {
        $document = new class extends BackupSettingsDocument {
            public function __construct() {}
            public function read(): ?string { return null; }
        };
        return new BackupLocation($document);
    }

    private function folderContaining(File $file, string $path): Folder {
        $root = $this->createMock(Folder::class);
        $root->method('get')->with($path)->willReturn($file);
        return $root;
    }

    private function storedMetadata(string $value, string $syncToken): IFilesMetadata {
        $metadata = $this->createMock(IFilesMetadata::class);
        $metadata->method('hasKey')->willReturnCallback(static fn (string $key): bool => $key === DescriptionService::KEY);
        $metadata->method('getString')->with(DescriptionService::KEY)->willReturn($value);
        $metadata->method('getSyncToken')->willReturn($syncToken);
        $metadata->method('setString')->with(DescriptionService::KEY, self::anything())->willReturn($metadata);
        return $metadata;
    }

    private function service(IFilesMetadataManager $manager): DescriptionService {
        return new DescriptionService(
            $this->createMock(IRootFolder::class),
            static fn () => $manager,
            (new \ReflectionClass(BackupLocation::class))->newInstanceWithoutConstructor(),
            new EmbeddedDescriptionReader(),
            $this->createMock(NodeAccessInfo::class),
            $this->createMock(SharedWriteGuard::class),
        );
    }

    private function manager(IFilesMetadata $metadata): IFilesMetadataManager {
        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->method('getMetadata')->willReturn($metadata);
        return $manager;
    }

    /** @param array<string, string> $ifd0 */
    private function metadata(bool $hasStored, ?string $stored, array $ifd0): IFilesMetadata {
        $metadata = $this->createMock(IFilesMetadata::class);
        $metadata->method('hasKey')->willReturnCallback(static fn (string $key): bool => $key === DescriptionService::KEY ? $hasStored : ($key === 'photos-ifd0' && $ifd0 !== []));
        if ($stored !== null) $metadata->method('getString')->with(DescriptionService::KEY)->willReturn($stored);
        $metadata->method('getArray')->with('photos-ifd0')->willReturn($ifd0);
        return $metadata;
    }

    private function file(string $bytes, int $id = 23, bool $updateable = true): File {
        $stream = tmpfile();
        fwrite($stream, $bytes);
        rewind($stream);
        $file = $this->createMock(File::class);
        $file->method('fopen')->willReturn($stream);
        $file->method('getEtag')->willReturn('etag-1');
        $file->method('getId')->willReturn($id);
        $file->method('getMimetype')->willReturn('image/jpeg');
        $file->method('isReadable')->willReturn(true);
        $file->method('isUpdateable')->willReturn($updateable);
        return $file;
    }

    private function jpegWithDescription(string $description): string {
        $value = $description . "\0";
        $tiff = 'II' . pack('v', 42) . pack('V', 8)
            . pack('v', 1)
            . pack('v', 0x010E) . pack('v', 2) . pack('V', strlen($value)) . pack('V', 26)
            . pack('V', 0) . $value;
        $payload = "Exif\0\0" . $tiff;
        return "\xFF\xD8\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . "\xFF\xDA";
    }
}
