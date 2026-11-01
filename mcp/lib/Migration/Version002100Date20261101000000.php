<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use Closure;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Adds the per-tool policy layer; old grants remain available as fallbacks for compatibility. */
class Version002100Date20261101000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable(ToolGrantsMapper::TABLE)) {
            return null;
        }
        $table = $schema->createTable(ToolGrantsMapper::TABLE);
        $table->addColumn('principal_type', Types::STRING, ['notnull' => true, 'length' => 8]);
        $table->addColumn('principal_key', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('principal_id', Types::TEXT, ['notnull' => true]);
        $table->addColumn('tool_name', Types::STRING, ['notnull' => true, 'length' => 96]);
        $table->addColumn('decision', Types::STRING, ['notnull' => true, 'length' => 8]);
        $table->setPrimaryKey(['principal_key', 'tool_name']);
        $table->addIndex(['principal_type', 'principal_key'], 'mcp_tgrant_principal');
        return $schema;
    }
}
