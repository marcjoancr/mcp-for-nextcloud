<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\BackgroundJob;

use OCA\Mcp\BackgroundJob\DescriptionBackfillJob;
use OCA\Mcp\Modules\Files\Descriptions\DescriptionIndexWriter;
use OCA\Mcp\Modules\Files\Descriptions\DescriptionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\FilesMetadata\Exceptions\FilesMetadataException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

final class DescriptionBackfillJobTest extends TestCase {
    public function testTheAppRegistersTheBoundedTimedBackfill(): void {
        $jobs = array_map('strval', iterator_to_array(simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml')->{'background-jobs'}->job, false));
        self::assertContains(DescriptionBackfillJob::class, $jobs);
        self::assertSame(60, DescriptionBackfillJob::INTERVAL);
        self::assertSame(200, DescriptionBackfillJob::BATCH_SIZE);
    }

    public function testBackfillsStoredDescriptionsAndResumesAfterEachSavedMetadataRecord(): void {
        $id = 21;
        $metadata = $this->createMock(IFilesMetadata::class);
        $metadata->expects(self::exactly(2))->method('hasKey')->with(DescriptionService::KEY)->willReturn(true);
        $metadata->expects(self::once())->method('getString')->with(DescriptionService::KEY)->willReturn('A lake at dusk');

        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->expects(self::once())->method('getMetadataForFiles')->with([$id])->willReturn([$id => $metadata]);
        $writer = $this->createMock(DescriptionIndexWriter::class);
        $writer->expects(self::once())->method('save')->with($manager, $metadata, $id, 'A lake at dusk');
        $config = $this->createMock(IAppConfig::class);
        $config->expects(self::once())->method('setValueInt')->with('mcp', DescriptionBackfillJob::CURSOR_KEY, $id, true);

        $job = new DescriptionBackfillJob($this->createMock(ITimeFactory::class), $this->createMock(\OCP\IDBConnection::class), $config, $manager, $writer);
        (new \ReflectionMethod($job, 'processBatch'))->invoke($job, [$id]);
    }

    public function testDoesNotWriteRecordsWithoutStoredDescriptions(): void {
        $metadata = $this->createMock(IFilesMetadata::class);
        $metadata->expects(self::once())->method('hasKey')->with(DescriptionService::KEY)->willReturn(false);
        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->expects(self::once())->method('getMetadataForFiles')->with([21])->willReturn([21 => $metadata]);
        $writer = $this->createMock(DescriptionIndexWriter::class);
        $writer->expects(self::never())->method('save');
        $config = $this->createMock(IAppConfig::class);
        $config->expects(self::once())->method('setValueInt')->with('mcp', DescriptionBackfillJob::CURSOR_KEY, 21, true);

        $job = new DescriptionBackfillJob($this->createMock(ITimeFactory::class), $this->createMock(\OCP\IDBConnection::class), $config, $manager, $writer);
        (new \ReflectionMethod($job, 'processBatch'))->invoke($job, [21]);
    }

    public function testRefreshesTheLatestSnapshotAfterAConcurrentWriteConflict(): void {
        $id = 22;
        $original = $this->createMock(IFilesMetadata::class);
        $original->method('hasKey')->with(DescriptionService::KEY)->willReturn(true);
        $original->method('getString')->with(DescriptionService::KEY)->willReturn('older description');
        $fresh = $this->createMock(IFilesMetadata::class);
        $fresh->method('hasKey')->with(DescriptionService::KEY)->willReturn(true);
        $fresh->method('getString')->with(DescriptionService::KEY)->willReturn('new concurrent description');

        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->expects(self::once())->method('getMetadataForFiles')->with([$id])->willReturn([$id => $original]);
        $manager->expects(self::once())->method('getMetadata')->with($id, false)->willReturn($fresh);
        $writer = $this->createMock(DescriptionIndexWriter::class);
        $calls = 0;
        $writer->expects(self::exactly(2))->method('save')->willReturnCallback(
            static function (IFilesMetadataManager $givenManager, IFilesMetadata $snapshot, int $fileId, string $description) use (&$calls, $manager, $original, $fresh, $id): void {
                ++$calls;
                self::assertSame($manager, $givenManager);
                self::assertSame($id, $fileId);
                if ($calls === 1) {
                    self::assertSame($original, $snapshot);
                    self::assertSame('older description', $description);
                    throw new FilesMetadataException('sync token conflict');
                }
                self::assertSame($fresh, $snapshot);
                self::assertSame('new concurrent description', $description);
            });
        $config = $this->createMock(IAppConfig::class);
        $config->expects(self::once())->method('setValueInt')->with('mcp', DescriptionBackfillJob::CURSOR_KEY, $id, true);

        $job = new DescriptionBackfillJob($this->createMock(ITimeFactory::class), $this->createMock(\OCP\IDBConnection::class), $config, $manager, $writer);
        (new \ReflectionMethod($job, 'processBatch'))->invoke($job, [$id]);
    }
}
