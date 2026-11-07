<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\BackgroundJob;

use OCA\Mcp\Modules\Files\Descriptions\DescriptionService;
use OCA\Mcp\Modules\Files\Descriptions\DescriptionIndexWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IDBConnection;
use OCP\IAppConfig;

/** Indexes descriptions that predate indexed description writes, without opening files or reading image metadata. */
final class DescriptionBackfillJob extends TimedJob {
    public const INTERVAL = 60;
    public const BATCH_SIZE = 200;
    public const CURSOR_KEY = 'description_index_backfill_cursor';
    private const COMPLETE = -1;
    private const MAX_SAVE_ATTEMPTS = 3;

    public function __construct(
        ITimeFactory $time,
        private IDBConnection $db,
        private IAppConfig $appConfig,
        private IFilesMetadataManager $metadata,
        private DescriptionIndexWriter $writer,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL);
        $this->setAllowParallelRuns(false);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    /** @param mixed $argument unused */
    protected function run($argument): void {
        $this->runBatch();
    }

    /** Process and checkpoint one page of stored descriptions. */
    public function runBatch(): void {
        $cursor = $this->appConfig->getValueInt('mcp', self::CURSOR_KEY, 0, true);
        if ($cursor === self::COMPLETE) return;

        $qb = $this->db->getQueryBuilder();
        $qb->select('file_id')
            ->from('files_metadata')
            ->where($qb->expr()->gt('file_id', $qb->createNamedParameter($cursor)))
            ->andWhere($qb->expr()->like('json', $qb->createNamedParameter('%"' . DescriptionService::KEY . '"%')))
            ->orderBy('file_id', 'ASC')
            ->setMaxResults(self::BATCH_SIZE);
        $result = $qb->executeQuery();
        try {
            $rows = $result->fetchAll();
        } finally {
            $result->closeCursor();
        }
        if ($rows === []) {
            $this->appConfig->setValueInt('mcp', self::CURSOR_KEY, self::COMPLETE, true);
            return;
        }
        $this->processBatch(array_map(static fn (array $row): int => (int)$row['file_id'], $rows));
    }

    /** @param list<int> $fileIds */
    private function processBatch(array $fileIds): void {
        // Load and verify snapshots in bounded bulk calls. The key-name SQL filter above is only a candidate filter;
        // this typed metadata read confirms the actual key before any write.
        $snapshots = $this->metadata->getMetadataForFiles($fileIds);
        foreach ($fileIds as $fileId) {
            $snapshot = $snapshots[$fileId] ?? null;
            if ($snapshot === null || !$snapshot->hasKey(DescriptionService::KEY)) continue;
            $this->writeLatest($fileId, $snapshot);
        }

        // The whole page is safe; persist the cursor once rather than writing appconfig per file.
        $this->appConfig->setValueInt('mcp', self::CURSOR_KEY, max($fileIds), true);
    }

    private function writeLatest(int $fileId, IFilesMetadata $snapshot): void {
        for ($attempt = 0; $attempt < self::MAX_SAVE_ATTEMPTS; $attempt++) {
            $metadata = $snapshot;
            if (!$metadata->hasKey(DescriptionService::KEY)) return;
            $description = $metadata->getString(DescriptionService::KEY);
            try {
                $this->writer->save($this->metadata, $metadata, $fileId, $description);
                return;
            } catch (\Throwable) {
                // Refresh and retry; the writer may have found a stale sync token or a failed index readback.
                try {
                    $snapshot = $this->metadata->getMetadata($fileId, false);
                } catch (FilesMetadataNotFoundException) {
                    return;
                }
            }
        }

        throw new \RuntimeException('Could not safely index legacy file description ' . $fileId);
    }
}
