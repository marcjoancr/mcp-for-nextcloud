<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Migration;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{SimpleMigrationStep,IOutput};
final class Version5125Date20261111130000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
        $s=$schemaClosure();if($s->hasTable('mail_mcp_sieve'))return $s;$t=$s->createTable('mail_mcp_sieve');
        foreach(['id'=>64,'user_id'=>255,'operation_key'=>64,'source_tool'=>32,'approval'=>64,'state'=>24,'candidate'=>72,'candidate_hash'=>64,'original_name'=>255,'original_hash'=>64,'profile'=>64,'observed_profile'=>64]as$n=>$length)$t->addColumn($n,Types::STRING,['notnull'=>true,'length'=>$length]);
        foreach(['account_id','connection_id','revision','created_at','follow_system']as$n)$t->addColumn($n,Types::BIGINT,['notnull'=>true]);
        $t->addColumn('program',Types::TEXT,['notnull'=>true,'length'=>262144]);$t->setPrimaryKey(['id']);$t->addUniqueIndex(['user_id','operation_key'],'mail_sieve_operation');$t->addIndex(['user_id','account_id'],'mail_sieve_account');return $s;
    }
}
