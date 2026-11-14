<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Forms\Mcp\Service;

use OCP\IDBConnection;
use OCP\Server;
/** One primary-database lock shared by ordinary native writers and MCP. */
final class FormsRevisionFence
{
    private int $depth = 0;
    private array $transactionCallbacks = [];
    /** Callbacks receive whether commit returned successfully. */
    public function afterTransaction(callable $callback): void {
        if (!$this->active()) {throw new \LogicException("Transaction completion callback requires active fence");}
        $this->transactionCallbacks[]=$callback;
    }
    /** Per outer transaction; discarded on commit and rollback. */
    private array $resultSourcesTouched = [];
    private array $linkedSourcesTouched = [];
    private ?string $session = null;
    private int $serialDepth = 0;
    private const LOCK = 'forms_mcp_revision_v1';
    private function sessionId(): string
    {
        return (string) $this->db->executeQuery($this->db->getDatabaseProvider(true) === 'postgres' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()')->fetchOne();
    }
    public function assertSession(): void
    {
        if ($this->session === null || $this->sessionId() !== $this->session) {
            throw new \RuntimeException('Forms primary session fence was lost');
        }
    }
    private function serialized(callable $work): mixed
    {
        if ($this->serialDepth > 0) {
            $this->assertSession();
            return $work();
        }
        if ($this->db->inTransaction()) {
            throw new \RuntimeException('Forms serialization requires its own primary session');
        }
        if (!SharingIdentity::verify()) {
            throw new \RuntimeException('Forms primary sharing source is unqualified');
        }
        $provider = $this->db->getDatabaseProvider(true);
        if (!in_array($provider, ['postgres', 'mariadb'], true)) {
            throw new \RuntimeException('Forms serialization provider is unqualified');
        }
        $session = $this->sessionId();
        $owned = $provider === 'postgres' ? (int) $this->db->executeQuery("SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND classid = 1179603533 AND objid = 1 AND objsubid = 2 AND granted")->fetchOne() > 0 : (string) $this->db->executeQuery('SELECT IS_USED_LOCK(?)', [self::LOCK])->fetchOne() === $session;
        if ($owned) {
            throw new \RuntimeException('Forms cannot borrow an unrelated primary session lock');
        }
        $this->session = $session;
        $this->serialDepth = 1;
        $acquired = false;
        $failure = null;
        try {
            if ($provider === 'postgres') {
                $this->db->executeQuery('SELECT pg_advisory_lock(1179603533, 1)')->fetchOne();
            } elseif ((string) $this->db->executeQuery('SELECT GET_LOCK(?, 60)', [self::LOCK])->fetchOne() !== '1') {
                throw new \RuntimeException('Forms session fence unavailable');
            }
            $acquired = true;
            $this->assertSession();
            return $work();
        } catch (\Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            try {
                // Acquisition can succeed even when its acknowledgement throws.
                $this->assertSession();
                $released = $provider === 'postgres' ? $this->db->executeQuery('SELECT CASE WHEN pg_advisory_unlock(1179603533, 1) THEN 1 ELSE 0 END')->fetchOne() : $this->db->executeQuery('SELECT RELEASE_LOCK(?)', [self::LOCK])->fetchOne();
                if ($acquired && (string) $released !== '1') {
                    throw new \RuntimeException('Forms primary session fence release failed');
                }
            } catch (\Throwable $releaseError) {
                // Qualified connections are nonpersistent. Closing also retires an uncertain session lease.
                $this->db->close();
                if ($failure === null) {
                    throw $releaseError;
                }
            } finally {
                $this->session = null;
                $this->serialDepth = 0;
            }
        }
    }
    public function __construct(private IDBConnection $db)
    {
    }
    public static function run(callable $work): mixed
    {
        return Server::get(self::class)->transaction($work);
    }
    public function active(): bool
    {
        return $this->depth > 0;
    }
    /** Keep one primary lease across a durable no-replay claim and its subsequent effect transaction. */
    public function withLock(callable $work): mixed
    {
        return $this->serialized($work);
    }
    public function revision(): string
    {
        $value = $this->db->executeQuery('SELECT revision FROM *PREFIX*forms_mcp_fence WHERE id = 1')->fetchOne();
        if ($value === false) {
            throw new \RuntimeException('Forms guard is not installed');
        }
        return (string) $value;
    }
    public function touchResultSource(int $formId): void
    {
        if (!$this->active()) {throw new \LogicException('Result source mutation requires the Forms transaction');}
        $this->assertSession();
        if (isset($this->resultSourcesTouched[$formId])) {return;}
        $epoch=ResultSourceRevision::epoch($formId);
        if ($epoch>=9223372036854775805) {throw new \RuntimeException('Forms result source epoch exhausted');}
        $exists=$this->db->executeQuery('SELECT form_id FROM *PREFIX*forms_mcp_result_epoch WHERE form_id = ?',[$formId])->fetchOne()!==false;
        // The global primary-session fence serializes lazy creation; do not catch a PostgreSQL unique violation.
        $count=$exists
            ? $this->db->executeStatement('UPDATE *PREFIX*forms_mcp_result_epoch SET revision = revision + 1 WHERE form_id = ? AND revision = ?',[$formId,$epoch])
            : $this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_result_epoch (form_id,revision) VALUES (?,1)',[$formId]);
        if ($count!==1) {throw new \RuntimeException('Forms result source mutation failed');}
        $this->resultSourcesTouched[$formId]=true;
        Server::get(LinkedMarkers::class)->dirty($formId);
    }
    public function touchLinkedSource(int $formId): void
    {
        if (!$this->active()) {throw new \LogicException('Linked Forms mutation requires its native fence');}
        if (isset($this->linkedSourcesTouched[$formId])) {return;}
        if ($this->db->executeQuery("SELECT association_id FROM *PREFIX*forms_mcp_linked WHERE form_id = ? AND state <> 'unlinked' AND dirty_generation >= 9223372036854775805",[$formId])->fetchOne()!==false) {
            throw new \RuntimeException('Linked Forms dirty generation is exhausted');
        }
        $this->db->executeStatement("UPDATE *PREFIX*forms_mcp_linked SET dirty_generation = dirty_generation + 1 WHERE form_id = ? AND state <> 'unlinked' AND dirty_generation < 9223372036854775805",[$formId]);
        $this->linkedSourcesTouched[$formId]=true;
    }
    public function transaction(callable $work): mixed
    {
        return $this->serialized(fn() => $this->transact($work));
    }
    private function transact(callable $work): mixed
    {
        if ($this->depth > 0) {
            return $work();
        }
        if ($this->db->inTransaction()) {
            throw new \RuntimeException('Forms guard requires its own primary transaction');
        }
        // Fresh policy checks during long file work must not reuse a MariaDB repeatable-read snapshot.
        // This changes only this transaction, never the connection default used by other jobs.
        $provider = $this->db->getDatabaseProvider(true);
        if ($provider === 'mariadb') {
            $this->db->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        $this->db->beginTransaction();
        $committed = false;
        try {
            if ($provider === 'postgres') {
                $this->db->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }
            $this->assertSession();
            // UPDATE is an exclusive current read on both admitted databases, including MySQL REPEATABLE READ.
            $count = $this->db->executeStatement('UPDATE *PREFIX*forms_mcp_fence SET revision = revision + 1 WHERE id = 1 AND revision < 9223372036854775806');
            if ($count !== 1) {
                throw new \RuntimeException('Forms guard fence is missing');
            }
            $this->depth = 1;
            $result = $work();
            $this->db->commit();
            $committed = true;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        } finally {
            $this->depth = 0;
            $callbacks=$this->transactionCallbacks;
            $this->transactionCallbacks=[];
            foreach ($callbacks as $callback) {$callback($committed);}
            $this->resultSourcesTouched = [];
            $this->linkedSourcesTouched = [];
        }
        try {Server::get(\OCA\Forms\Mcp\Comments\CommentsOutbox::class)->drain();} catch (\Throwable) {}
        Server::get(ManagedSharePhase::class)->drain();
        // No transport/event failure can roll back a committed answer set.
        try {
            Server::get(FormsEffectOutbox::class)->drain();
        } catch (\Throwable) {
        }
        return $result;
    }
}
