<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Mcp\Service;
use OCP\IDBConnection;
use OCP\Server;
/** One primary-database lock shared by ordinary native writers and MCP. */
final class FormsRevisionFence {
    private int $depth = 0;
    public function __construct(private IDBConnection $db) {}
    public static function run(callable $work): mixed {
        return Server::get(self::class)->transaction($work);
    }
    public function active(): bool { return $this->depth > 0; }
    public function revision(): string {
        $value = $this->db->executeQuery('SELECT revision FROM *PREFIX*forms_mcp_fence WHERE id = 1')->fetchOne();
        if ($value === false) { throw new \RuntimeException('Forms guard is not installed'); }
        return (string)$value;
    }
    public function transaction(callable $work): mixed {
        if ($this->depth > 0) { return $work(); }
        if ($this->db->inTransaction()) { throw new \RuntimeException('Forms guard requires its own primary transaction'); }
        $this->db->beginTransaction();
        try {
            // UPDATE is an exclusive current read on both admitted databases, including MySQL REPEATABLE READ.
            $count = $this->db->executeStatement('UPDATE *PREFIX*forms_mcp_fence SET revision = revision + 1 WHERE id = 1 AND revision < 9223372036854775806');
            if ($count !== 1) { throw new \RuntimeException('Forms guard fence is missing'); }
            $this->depth = 1;
            $result = $work();
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $error;
        } finally { $this->depth = 0; }
        // No transport/event failure can roll back a committed answer set.
        try { Server::get(FormsEffectOutbox::class)->drain(); } catch (\Throwable) {}
        return $result;
    }
}
