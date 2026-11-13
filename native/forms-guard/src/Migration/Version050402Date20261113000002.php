<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Migration;
use Closure;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{IOutput,SimpleMigrationStep};
final class Version050402Date20261113000002 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options): ?ISchemaWrapper {
        $s=$schemaClosure();
        if (!$s->hasTable('forms_mcp_file_shares')) {
            $t=$s->createTable('forms_mcp_file_shares');
            $t->addColumn('files_share_id',Types::STRING,['length'=>128,'notnull'=>true]);
            foreach (['form_id','native_share_id','folder_id'] as $n) {$t->addColumn($n,Types::BIGINT,['notnull'=>true]);}
            $t->setPrimaryKey(['files_share_id']);
        }
        if (!$s->hasTable('forms_mcp_folders')) {
            $t=$s->createTable('forms_mcp_folders');
            foreach (['form_id','folder_id'] as $n) {$t->addColumn($n,Types::BIGINT,['notnull'=>true]);}
            $t->setPrimaryKey(['form_id','folder_id']);
        }
        return $s;
    }
}
