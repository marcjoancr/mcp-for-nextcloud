<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
use OCA\Forms\Mcp\Service\CircleReadData;
/** Scalar-only projection of Forms' native TYPE_USER filter. No membership authority or model hydration. */
final class UserProjection {
    public const MAX_ROWS = 1000;
    private int $rows = 0;
    private int $bytes = 2;
    private array $users = [];

    /** A single native result, consumed in batches of at most 100; never returns a partial list. */
    public static function consume(callable $fetch, callable $assertActive, callable $close): array {
        $projection = new self();
        try {
            $batch = [];
            while (true) {
                $assertActive();
                $row = $fetch();
                if ($row === false) {break;}
                if ($projection->rows + count($batch) >= self::MAX_ROWS) {throw new \RuntimeException('Circle user projection row budget exceeded');}
                $batch[] = $row;
                if (count($batch) === 100) {$projection->append($batch);$batch = [];}
            }
            if ($batch !== []) {$projection->append($batch);}
            $assertActive();
            CircleReadData::page([['users' => $projection->users]]);
            return $projection->users;
        } finally {$close();}
    }

    private function append(array $batch): void {
        CircleReadData::page($batch);
        foreach ($batch as $row) {
            if (++$this->rows > self::MAX_ROWS) {throw new \RuntimeException('Circle user projection row budget exceeded');}
            if (array_keys($row) !== ['single_id', 'user_id', 'user_type']
                || !is_string($row['single_id']) || $row['single_id'] === '' || strlen($row['single_id']) > 31
                || !is_string($row['user_id']) || strlen($row['user_id']) > 256) {
                throw new \RuntimeException('Circle user projection identity is malformed');
            }
            $type = $row['user_type'];
            if (is_string($type) && preg_match('/^(?:0|1|2|4|8|16|10000)$/D', $type)) {$type = (int)$type;}
            if (!is_int($type) || !in_array($type, [0, 1, 2, 4, 8, 16, 10000], true)
                || ($type === 1 && $row['user_id'] === '')) {
                throw new \RuntimeException('Circle user projection type is malformed');
            }
            // page() above has already rejected encoding errors, objects and resources.
            $this->bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR)) + 1;
            if ($this->bytes > 1048576) {throw new \RuntimeException('Circle user projection byte budget exceeded');}
            // Native array_filter followed by array_map preserves original indexes and duplicate IDs.
            if ($type === 1) {$this->users[$this->rows - 1] = $row['user_id'];}
        }
    }
}
