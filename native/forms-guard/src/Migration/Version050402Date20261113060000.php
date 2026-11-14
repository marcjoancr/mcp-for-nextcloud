<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Migration;
use Closure;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{IOutput,SimpleMigrationStep};
final class Version050402Date20261113060000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options): ?ISchemaWrapper {
        $schema=$schemaClosure();
        if (!$schema->hasTable('forms_mcp_linked')) {
            $table=$schema->createTable('forms_mcp_linked');
            $table->addColumn('association_id',Types::STRING,['length'=>64,'notnull'=>true]);
            foreach (['form_id','file_id','version','dirty_generation','completed_generation','lease_until','attempted_at'] as $name) {
                $table->addColumn($name,Types::BIGINT,['notnull'=>true]);
            }
            $table->addColumn('file_format',Types::STRING,['length'=>4,'notnull'=>true]);
            $table->addColumn('state',Types::STRING,['length'=>24,'notnull'=>true]);
            $table->addColumn('lease_token',Types::STRING,['length'=>64,'notnull'=>true]);
            $table->addColumn('payload',Types::TEXT,['notnull'=>true]);
            $table->setPrimaryKey(['association_id']);
            $table->addIndex(['form_id','file_id','state'],'forms_mcp_linked_tuple');
            $table->addIndex(['state','attempted_at'],'forms_mcp_linked_pending');
        }
        return $schema;
    }
}
