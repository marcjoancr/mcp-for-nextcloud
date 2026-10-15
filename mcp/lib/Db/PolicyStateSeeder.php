<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use OCP\IDBConnection;

/**
 * Creates the single policy state row, and only that row.
 *
 * A fresh install never runs a migration's postSchemaChange(): the server asks for schema only, because an app that
 * starts empty has nothing to migrate. The seed therefore also runs as a declared install and post-migration step,
 * both calling this one implementation, so install and upgrade leave the same state behind.
 *
 * The insert is insert-if-absent, so re-running it against a live instance, or racing another initialisation, keeps
 * the revision already committed. Runtime code never seeds: a missing row is a storage failure, not a default.
 */
final class PolicyStateSeeder {
    public function __construct(private IDBConnection $db) {}

    public function seed(): void {
        // insertIfNotExist takes the raw table name, so the server prefix has to be asked for explicitly.
        $this->db->insertIfNotExist('*PREFIX*' . PolicyStateMapper::TABLE,
            ['id' => 1, 'revision' => 0, 'lock_seq' => 0, 'auth_epoch' => 0], ['id']);
    }
}