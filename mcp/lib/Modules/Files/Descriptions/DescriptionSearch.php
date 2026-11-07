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
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IDBConnection;

/** Candidate IDs are never results. Resolve accessible paths and verify the live description before returning a file. */
class DescriptionSearch {
    private const PAGE = 64;

    public function __construct(
        private IDBConnection $db,
        private IRootFolder $roots,
        private IFilesMetadataManager $metadata,
        private FilesAccessGuard $visibility,
        private BackupLocation $backups,
    ) {}

    /** @return list<File> */
    public function find(Folder $root, string $query, int $limit): array {
        if ($limit < 1 || strlen($query) > DescriptionService::MAX_BYTES) {
            return [];
        }
        $words = DescriptionIndex::words($query);
        if ($words === []) {
            return [];
        }
        // Prefer a specific candidate word. Confirm the entire phrase (including order) below.
        $anchor = $words[0];
        foreach ($words as $word) {
            if (strlen($word) > strlen($anchor)) {
                $anchor = $word;
            }
        }
        $bucket = DescriptionIndex::bucket($anchor);
        $this->backups->current();
        $found = [];
        foreach ($this->mountRanges($root) as $group) {
            $after = 0;
            do {
                $ids = $this->candidates($group, $bucket, $after);
                $visible = [];
                foreach ($ids as $id) {
                    $after = $id;
                    if (isset($found[$id])) continue;
                    foreach ($root->getById($id) as $node) {
                        if (!$node instanceof File || !$node->isReadable()) continue;
                        $path = $root->getRelativePath($node->getPath());
                        if ($path === null || $this->backups->containsInSnapshot(PathGuard::normalize($path))) continue;
                        if ($this->visibility->isVisible($node)) {
                            $visible[$id] = $node;
                            break;
                        }
                    }
                }
                if ($visible !== []) {
                    $snapshots = $this->metadata->getMetadataForFiles(array_keys($visible));
                    foreach ($visible as $id => $node) {
                        $snapshot = $snapshots[$id] ?? null;
                        if ($snapshot?->hasKey(DescriptionService::KEY)
                            && DescriptionIndex::matches($snapshot->getString(DescriptionService::KEY), $words)) {
                            $found[$id] = $node;
                            if (count($found) >= $limit) {
                                return array_values($found);
                            }
                        }
                    }
                }
            } while (count($ids) === self::PAGE);
        }
        return array_values($found);
    }

    /**
     * Limit the database work to actual mounted subtrees. Sharing a single file must not scan all
     * descriptions on its owner's storage. These ranges are a prefilter, never an access decision.
     * @return \Generator<int, list<array{storage:int, path:string}>>
     */
    private function mountRanges(Folder $root): \Generator {
        $ids = [];
        foreach ($this->visibility->scopeRoots($root) ?? [$root] as $scope) {
            $ids[(int)$scope->getId()] = true;
            foreach ($this->roots->getMountsIn($scope->getPath()) as $mount) {
                $id = (int)$mount->getStorageRootId();
                if ($id > 0) $ids[$id] = true;
            }
        }
        foreach (array_chunk(array_keys($ids), 64) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('storage', 'path')->from('filecache')
                ->where($qb->expr()->in('fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            $result = $qb->executeQuery();
            try {
                $group = $result->fetchAll();
            } finally {
                $result->closeCursor();
            }
            if ($group !== []) yield $group;
        }
    }

    /** @param list<array{storage:int, path:string}> $ranges @return list<int> */
    private function candidates(array $ranges, string $bucket, int $after): array {
        // The native metadata query helper cannot compare STRING_LIST fields on NC 33–35.
        // Read its indexed rows, limited to mounted storage; only getById + FilesAccessGuard authorizes a hit.
        $qb = $this->db->getQueryBuilder();
        $allowed = [];
        foreach ($ranges as $range) {
            $storage = $qb->expr()->eq('f.storage', $qb->createNamedParameter((int)$range['storage'], IQueryBuilder::PARAM_INT));
            $path = $range['path'];
            $allowed[] = $path === '' ? $storage : $qb->expr()->andX($storage, $qb->expr()->orX(
                $qb->expr()->eq('f.path', $qb->createNamedParameter($path)),
                $qb->expr()->like('f.path', $qb->createNamedParameter($this->db->escapeLikeParameter($path) . '/%')),
            ));
        }
        $qb->selectDistinct('i.file_id')->from('files_metadata_index', 'i')
            ->innerJoin('i', 'filecache', 'f', $qb->expr()->eq('f.fileid', 'i.file_id'))
            ->where($qb->expr()->eq('i.meta_key', $qb->createNamedParameter(DescriptionIndex::KEY)))
            ->andWhere($qb->expr()->eq('i.meta_value_string', $qb->createNamedParameter($bucket)))
            ->andWhere($qb->expr()->orX(...$allowed))
            ->andWhere($qb->expr()->gt('i.file_id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
            ->orderBy('i.file_id', 'ASC')->setMaxResults(self::PAGE);
        $result = $qb->executeQuery();
        try {
            return array_values(array_map('intval', $result->fetchFirstColumn()));
        } finally {
            $result->closeCursor();
        }
    }
}
