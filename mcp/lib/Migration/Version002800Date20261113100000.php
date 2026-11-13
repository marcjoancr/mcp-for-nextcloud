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
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;
class Version002800Date20261113100000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
        $schema=$schemaClosure();
        if(!$schema->hasTable('mcp_forms_jobs')) {
            $t=$schema->createTable('mcp_forms_jobs');
            $t->addColumn('job_id',Types::STRING,['length'=>64,'notnull'=>true]);
            $t->addColumn('actor_uid',Types::STRING,['length'=>64,'notnull'=>true]);
            $t->addColumn('state',Types::STRING,['length'=>32,'notnull'=>true]);
            $t->addColumn('version',Types::BIGINT,['notnull'=>true]);
            $t->addColumn('updated_at',Types::BIGINT,['notnull'=>true]);
            $t->addColumn('payload',Types::TEXT,['notnull'=>true]);
            $t->setPrimaryKey(['job_id']);$t->addIndex(['actor_uid','state'],'mcp_forms_jobs_actor');
        }
        if(!$schema->hasTable('mcp_forms_downloads')) {
            $t=$schema->createTable('mcp_forms_downloads');
            foreach(['token_hash'=>64,'job_id'=>64,'actor_uid'=>64] as $name=>$length)$t->addColumn($name,Types::STRING,['length'=>$length,'notnull'=>true]);
            $t->addColumn('expires_at',Types::BIGINT,['notnull'=>true]);
            $t->addColumn('used',Types::SMALLINT,['notnull'=>true,'default'=>0]);$t->setPrimaryKey(['token_hash']);
            $t->addIndex(['expires_at'],'mcp_forms_dl_expires');
        }
        return $schema;
    }
}
