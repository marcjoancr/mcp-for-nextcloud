<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Migration;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version002800Date20261113110000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
        $schema=$schemaClosure();
        if(!$schema->hasTable('mcp_forms_spool')) {
            $t=$schema->createTable('mcp_forms_spool');
            foreach(['job_id'=>64,'actor_uid'=>64,'state'=>16] as $key=>$length)$t->addColumn($key,Types::STRING,['length'=>$length,'notnull'=>true]);
            foreach(['version','reserved_bytes','used_bytes','expires_at'] as $key)$t->addColumn($key,Types::BIGINT,['notnull'=>true]);
            $t->addColumn('io_token',Types::STRING,['length'=>64,'notnull'=>true,'default'=>'']);
            $t->addColumn('part_count',Types::INTEGER,['notnull'=>true,'default'=>0]);
            $t->setPrimaryKey(['job_id']);$t->addIndex(['state','expires_at'],'mcp_forms_cache_expiry');$t->addIndex(['actor_uid','state'],'mcp_forms_cache_owner');
        }
        if(!$schema->hasTable('mcp_forms_spool_parts')) {
            $t=$schema->createTable('mcp_forms_spool_parts');
            foreach(['job_id','part_key'] as $key)$t->addColumn($key,Types::STRING,['length'=>64,'notnull'=>true]);
            $t->addColumn('cipher_bytes',Types::BIGINT,['notnull'=>true]);$t->setPrimaryKey(['job_id','part_key']);
        }
        return $schema;
    }
}
