<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Migration;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{SimpleMigrationStep,IOutput};
final class Version5125Date20261111020000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
        $s=$schemaClosure();if(!$s->hasTable('mail_mcp_erasure')){$t=$s->createTable('mail_mcp_erasure');foreach(['id'=>64,'user_id'=>255,'state'=>16] as $name=>$length)$t->addColumn($name,Types::STRING,['notnull'=>true,'length'=>$length]);$t->addColumn('created_at',Types::BIGINT,['notnull'=>true]);$t->setPrimaryKey(['id']);$t->addIndex(['state','created_at'],'mail_mcp_erasure_due');$t->addIndex(['user_id','state'],'mail_mcp_erasure_owner');}
        if(!$s->hasTable('mail_mcp_erase_accounts')){$t=$s->createTable('mail_mcp_erase_accounts');$t->addColumn('erasure_id',Types::STRING,['notnull'=>true,'length'=>64]);$t->addColumn('account_id',Types::BIGINT,['notnull'=>true]);$t->setPrimaryKey(['erasure_id','account_id']);$t->addIndex(['account_id'],'mail_mcp_erase_account');}return $s;
    }
}
