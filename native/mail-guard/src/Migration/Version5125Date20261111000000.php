<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Migration;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{SimpleMigrationStep,IOutput};
final class Version5125Date20261111000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
        $s=$schemaClosure();if($s->hasTable('mail_mcp_deliveries'))return $s;
        $t=$s->createTable('mail_mcp_deliveries');
        foreach(['id'=>64,'user_id'=>255,'operation_key'=>64,'revision'=>64,'content_revision'=>64,'state'=>16,'sent_state'=>16,'source_tool'=>32,'timezone'=>64] as $name=>$length)$t->addColumn($name,Types::STRING,['notnull'=>true,'length'=>$length]);
        foreach(['draft_id','account_id','connection_id','send_at','created_at'] as $name)$t->addColumn($name,Types::BIGINT,['notnull'=>true]);
        $t->addColumn('alias_id',Types::BIGINT,['notnull'=>false,'default'=>null]);
        foreach(['artifact','sent_proof'] as $name)$t->addColumn($name,Types::TEXT,['notnull'=>true,'length'=>1073741824]);
        $t->setPrimaryKey(['id']);$t->addUniqueIndex(['user_id','operation_key'],'mail_mcp_delivery_operation');$t->addIndex(['state','send_at'],'mail_mcp_delivery_due');$t->addIndex(['user_id','draft_id','state'],'mail_mcp_delivery_source');$t->addIndex(['user_id','account_id','state'],'mail_mcp_delivery_account');return $s;
    }
}
