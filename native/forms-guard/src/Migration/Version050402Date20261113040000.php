<?php

/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare (strict_types=1);
namespace OCA\Forms\Migration;

use Closure;
use OCP\DB\{ISchemaWrapper, Types};
use OCP\Migration\{IOutput, SimpleMigrationStep};
final class Version050402Date20261113040000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        if (!$schema->hasTable('forms_mcp_share_intents')) {
            $table = $schema->createTable('forms_mcp_share_intents');
            foreach (['intent_key' => 64, 'operation_key' => 128, 'phase' => 32] as $name => $length) {
                $table->addColumn($name, Types::STRING, ['length' => $length, 'notnull' => true]);
            }
            foreach (['form_id', 'native_share_id'] as $name) {
                $table->addColumn($name, Types::BIGINT, ['notnull' => true]);
            }
            $table->setPrimaryKey(['intent_key']);
            $table->addIndex(['operation_key'], 'forms_mcp_share_op');
        }
        return $schema;
    }
}
