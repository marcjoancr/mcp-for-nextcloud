<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Descriptions;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IDBConnection;

/** Keep the native sync-token update and replacement of its searchable rows atomic. */
class DescriptionIndexWriter {
    public function __construct(private IDBConnection $db) {}

    public function save(IFilesMetadataManager $manager, IFilesMetadata $snapshot, int $fileId, string $description): void {
        // Core ignores setStringList if only the indexed flag changed. Force a refresh even when repairing an index.
        $snapshot->unset(DescriptionIndex::KEY);
        DescriptionIndex::apply($snapshot, $description);
        $this->db->beginTransaction();
        try {
            $manager->saveMetadata($snapshot);
            $saved = $manager->getMetadata($fileId, false);
            // Core can log a conflict or indexing error rather than throwing. Do not acknowledge a partial write.
            $terms = DescriptionIndex::terms($description);
            if (!$saved->hasKey(DescriptionService::KEY) || $saved->getString(DescriptionService::KEY) !== $description
                || !$saved->hasKey(DescriptionIndex::KEY) || !$saved->isIndex(DescriptionIndex::KEY)
                || $saved->getStringList(DescriptionIndex::KEY) !== $terms || $this->storedTerms($fileId) !== $terms) {
                throw new \RuntimeException('Description index readback mismatch');
            }
            $this->db->commit();
        } catch (\Throwable $failure) {
            $this->db->rollBack();
            throw $failure;
        }
    }

    /** @return list<string> */
    private function storedTerms(int $fileId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('meta_value_string')->from('files_metadata_index')
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('meta_key', $qb->createNamedParameter(DescriptionIndex::KEY)));
        $result = $qb->executeQuery();
        try {
            $terms = $result->fetchFirstColumn();
            sort($terms, SORT_STRING);
            return $terms;
        } finally {
            $result->closeCursor();
        }
    }
}
