<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Migration;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version002800Date20261113120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
        $schema=$schemaClosure();$table=$schema->getTable('mcp_forms_spool');
        if($table->hasColumn('sql_bytes'))return null;
        // Existing candidate leases are unaccounted until their generated content is safely compacted.
        $table->addColumn('sql_bytes',Types::BIGINT,['notnull'=>true,'default'=>-1]);
        return $schema;
    }
}
