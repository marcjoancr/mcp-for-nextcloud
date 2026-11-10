<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Migration;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{SimpleMigrationStep,IOutput};
final class Version5125Date20261110200000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
        $s=$schemaClosure();$a=$s->getTable('mail_accounts');
        if(!$a->hasColumn('mcp_auth_revision'))$a->addColumn('mcp_auth_revision',Types::BIGINT,['notnull'=>true,'default'=>0]);
        if(!$s->hasTable('mail_mcp_auth_contexts')){
            $t=$s->createTable('mail_mcp_auth_contexts');
            foreach(['id'=>64,'source_key'=>64,'user_id'=>255,'kind'=>32,'phase'=>16] as $name=>$length)$t->addColumn($name,Types::STRING,['notnull'=>true,'length'=>$length]);
            $t->addColumn('target_id',Types::BIGINT,['notnull'=>false,'default'=>null]);
            $t->addColumn('intent_json',Types::TEXT,['notnull'=>true]);
            foreach(['created_at','expires_at'] as $name)$t->addColumn($name,Types::BIGINT,['notnull'=>true]);
            foreach(['activated_at','proof_revision','proof_at'] as $name)$t->addColumn($name,Types::BIGINT,['notnull'=>false,'default'=>null]);
            $t->addColumn('proof_kind',Types::STRING,['length'=>24,'notnull'=>false,'default'=>null]);
            $t->addColumn('proof_protocols',Types::SMALLINT,['notnull'=>true,'default'=>0]);
            $t->addColumn('consumed_by',Types::STRING,['length'=>128,'notnull'=>false,'default'=>null]);
            $t->setPrimaryKey(['id']);$t->addUniqueIndex(['user_id','source_key'],'mail_mcp_auth_source');$t->addIndex(['user_id','expires_at'],'mail_mcp_auth_expiry');
        }
        if(!$s->hasTable('mail_mcp_auth_states')){
            $t=$s->createTable('mail_mcp_auth_states');
            foreach(['id','context_id'] as $name)$t->addColumn($name,Types::STRING,['notnull'=>true,'length'=>64]);
            foreach(['account_id','created_at','expires_at'] as $name)$t->addColumn($name,Types::BIGINT,['notnull'=>true]);
            $t->addColumn('used',Types::BOOLEAN,['notnull'=>true,'default'=>false]);
            $t->addColumn('completed',Types::BOOLEAN,['notnull'=>true,'default'=>false]);
            $t->addColumn('attempt_id',Types::STRING,['length'=>64,'notnull'=>false,'default'=>null]);
            $t->setPrimaryKey(['id']);$t->addIndex(['context_id'],'mail_mcp_auth_context');
            $t->addForeignKeyConstraint($s->getTable('mail_mcp_auth_contexts')->getName(),['context_id'],['id'],['onDelete'=>'CASCADE'],'mail_mcp_auth_state_context');
        }
        return $s;
    }
}
