<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
use OCA\Forms\Mcp\Service\CircleReadData;
/** Scalar-only output of the native Forms team query. No model hydration or permission algorithm. */
final class TeamProjection {
    public const MAX_ROWS = 1000;
    private int $rows = 0;
    private int $bytes = 2;
    private array $ids = [];

    /** A single native result, consumed in batches of at most 100; never returns a partial list. */
    public static function consume(callable $fetch, callable $assertActive, callable $close): array {
        $projection = new self();
        try {
            $batch = [];
            while (true) {
                $assertActive();
                $row = $fetch();
                if ($row === false) {break;}
                if ($projection->rows + count($batch) >= self::MAX_ROWS) {throw new \RuntimeException('Circle team projection row budget exceeded');}
                $batch[] = $row;
                if (count($batch) === 100) {$projection->append($batch);$batch = [];}
            }
            if ($batch !== []) {$projection->append($batch);}
            $assertActive();
            CircleReadData::page([['ids' => $projection->ids]]);
            return $projection->ids;
        } finally {$close();}
    }

    private function append(array $batch): void {
        CircleReadData::page($batch);
        foreach ($batch as $row) {
            if (++$this->rows > self::MAX_ROWS) {throw new \RuntimeException('Circle team projection row budget exceeded');}
            if (array_keys($row) !== ['unique_id'] || !is_string($row['unique_id'])
                || preg_match('/\A.{1,31}\z/us', $row['unique_id']) !== 1) {
                throw new \RuntimeException('Circle team projection identity is malformed');
            }
            // page() above has already rejected encoding errors, objects and resources.
            $this->bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR)) + 1;
            if ($this->bytes > 1048576) {throw new \RuntimeException('Circle team projection byte budget exceeded');}
            // Native getRows appends each Circle; Forms array_map keeps that list order and duplicates.
            $this->ids[] = $row['unique_id'];
        }
    }
}
