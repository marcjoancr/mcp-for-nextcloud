<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Migration;
use Closure;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{IOutput,SimpleMigrationStep};
final class Version050402Date20261113050000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
        $schema=$schemaClosure();
        if(!$schema->hasTable('forms_mcp_result_epoch')) {
            $table=$schema->createTable('forms_mcp_result_epoch');
            $table->addColumn('form_id',Types::BIGINT,['notnull'=>true]);
            $table->addColumn('revision',Types::BIGINT,['notnull'=>true]);
            $table->setPrimaryKey(['form_id']);
        }
        return $schema;
    }
}
