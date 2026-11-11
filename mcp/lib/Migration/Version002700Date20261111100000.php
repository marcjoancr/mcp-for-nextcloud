<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Migration;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;
class Version002700Date20261111100000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
        $s=$schemaClosure();
        if(!$s->hasTable('mcp_actor_incarnations')) {
            $t=$s->createTable('mcp_actor_incarnations');
            foreach(['actor_key'=>64,'actor_uid'=>64,'incarnation'=>64]as$n=>$length)$t->addColumn($n,Types::STRING,['length'=>$length,'notnull'=>true]);
            $t->setPrimaryKey(['actor_key']);
        }
        if(!$s->hasTable('mcp_operation_origins')) {
            $t=$s->createTable('mcp_operation_origins');
            foreach(['native_key'=>64,'scope_key'=>64,'operation_id'=>128]as$n=>$length)$t->addColumn($n,Types::STRING,['length'=>$length,'notnull'=>true]);
            $t->setPrimaryKey(['native_key']);
        }
        return $s;
    }
}
