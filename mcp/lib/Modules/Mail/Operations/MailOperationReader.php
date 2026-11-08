<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Operations;

use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Current original-action access is checked again; receipts never contain message bodies or attachment handles. */
final class MailOperationReader {
    public function __construct(private OperationJournal $journal, private \Closure $allowsTool) {}
    public function get(string $uid, string $id): array {
        $receipt = $this->journal->get($uid, $id);
        if ($receipt === null || !($this->allowsTool)($uid, $receipt['tool'])) throw new ToolFailure('Operation not found.');
        return ['operation' => $receipt];
    }
    public function list(string $uid, int $limit, int $offset): array {
        $rows = $this->journal->list($uid, $limit, $offset);
        $visible = array_values(array_filter($rows, fn(array $r): bool => ($this->allowsTool)($uid, $r['tool'])));
        return ['operations' => $visible, 'next_offset' => count($rows) === $limit ? $offset + $limit : null];
    }
}
