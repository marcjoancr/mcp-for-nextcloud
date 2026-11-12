<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Migration;

use Closure;
use OCA\Mcp\Db\OperationsMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002700Date20261107000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable(OperationsMapper::TABLE)) return null;
        $t = $schema->createTable(OperationsMapper::TABLE);
        foreach (['scope_key' => 64, 'operation_id' => 128, 'tool_name' => 96, 'effect_digest' => 64, 'state' => 24] as $name => $len)
            $t->addColumn($name, Types::STRING, ['notnull' => true, 'length' => $len]);
        $t->addColumn('actor_uid', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
        $t->addColumn('target_key', Types::STRING, ['notnull' => false, 'length' => 64]);
        $t->addColumn('resource_refs', Types::TEXT, ['notnull' => true]);
        $t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
        $t->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
        $t->setPrimaryKey(['scope_key', 'operation_id']);
        $t->addUniqueIndex(['target_key'], 'mcp_operation_target');
        $t->addIndex(['scope_key', 'created_at'], 'mcp_operation_scope');
        return $schema;
    }
}
