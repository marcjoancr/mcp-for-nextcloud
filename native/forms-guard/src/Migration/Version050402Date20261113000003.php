<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Migration;
use Closure;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{IOutput,SimpleMigrationStep};
final class Version050402Date20261113000003 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options): ?ISchemaWrapper {
        $s=$schemaClosure();
        if (!$s->hasTable('forms_mcp_artifacts')) {
            $t=$s->createTable('forms_mcp_artifacts');
            foreach (['form_id','file_id'] as $n) {$t->addColumn($n,Types::BIGINT,['notnull'=>true]);}
            $t->setPrimaryKey(['file_id']);
        }
        return $s;
    }
}
