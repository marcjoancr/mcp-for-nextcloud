<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Migration;
use Closure;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{IOutput,SimpleMigrationStep};
final class Version050402Date20261113000001 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options): ?ISchemaWrapper {
        $s=$schemaClosure();
        if (!$s->hasTable('forms_mcp_uploads')) {
            $t=$s->createTable('forms_mcp_uploads');
            foreach (['handle_hash','actor_hash','sha256'] as $n) {$t->addColumn($n,Types::STRING,['length'=>64,'notnull'=>true]);}
            foreach (['form_id','question_id','upload_id','expires','consumed'] as $n) {$t->addColumn($n,Types::BIGINT,['notnull'=>true]);}
            $t->setPrimaryKey(['handle_hash']);
        }
        if (!$s->hasTable('forms_mcp_file_guards')) {
            $t=$s->createTable('forms_mcp_file_guards');
            $t->addColumn('form_id',Types::BIGINT,['notnull'=>true]);$t->addColumn('question_id',Types::BIGINT,['notnull'=>true]);
            $t->setPrimaryKey(['form_id','question_id']);
        }
        return $s;
    }
}
