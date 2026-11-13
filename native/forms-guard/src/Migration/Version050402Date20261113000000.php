<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);

namespace OCA\Forms\Migration;
use Closure;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\IDBConnection;
use OCP\Migration\{IOutput,SimpleMigrationStep};
final class Version050402Date20261113000000 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $db) {}
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options): ?ISchemaWrapper {
        $s=$schemaClosure();
        if (!$s->hasTable('forms_mcp_fence')) {
            $t=$s->createTable('forms_mcp_fence');$t->addColumn('id',Types::INTEGER,['notnull'=>true]);$t->addColumn('revision',Types::BIGINT,['notnull'=>true]);$t->setPrimaryKey(['id']);
        }
        if (!$s->hasTable('forms_mcp_operations')) {
            $t=$s->createTable('forms_mcp_operations');
            foreach (['operation_key'=>128,'actor_hash'=>64,'fingerprint'=>64] as $n=>$len) {$t->addColumn($n,Types::STRING,['length'=>$len,'notnull'=>true]);}
            $t->addColumn('receipt',Types::TEXT,['notnull'=>true]);$t->setPrimaryKey(['operation_key']);
        }
        if (!$s->hasTable('forms_mcp_effects')) {
            $t=$s->createTable('forms_mcp_effects');
            foreach (['effect_key'=>64,'operation_key'=>128,'kind'=>32,'phase'=>32] as $n=>$len) {$t->addColumn($n,Types::STRING,['length'=>$len,'notnull'=>true]);}
            foreach (['form_id','entity_id'] as $n) {$t->addColumn($n,Types::BIGINT,['notnull'=>true]);}
            $t->setPrimaryKey(['effect_key']);$t->addIndex(['operation_key'],'forms_mcp_effect_op');
        }
        return $s;
    }
    public function postSchemaChange(IOutput $output,Closure $schemaClosure,array $options): void {
        $existing=$this->db->executeQuery('SELECT id FROM *PREFIX*forms_mcp_fence WHERE id = 1')->fetchOne();
        if ($existing===false) {$this->db->executeStatement('INSERT INTO *PREFIX*forms_mcp_fence (id, revision) VALUES (1, 0)');}
    }
}
