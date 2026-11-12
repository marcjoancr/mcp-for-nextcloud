<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version002700Date20261108000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options): ?ISchemaWrapper {
        $s=$schemaClosure();if($s->hasTable('mcp_mail_setup'))return null;
        $t=$s->createTable('mcp_mail_setup');
        foreach(['scope_key'=>64,'operation_id'=>128,'kind'=>32,'intent_digest'=>64,'before_digest'=>64,'phase'=>16] as $k=>$length)$t->addColumn($k,Types::STRING,['length'=>$length,'notnull'=>true]);
        $t->addColumn('before_ids',Types::TEXT,['notnull'=>true]);
        foreach(['target_account_id','observed_account_id'] as $k)$t->addColumn($k,Types::BIGINT,['notnull'=>false]);
        $t->addColumn('completion_id',Types::STRING,['length'=>128,'notnull'=>false]);
        foreach(['created_at','expires_at'] as $k)$t->addColumn($k,Types::BIGINT,['notnull'=>true]);
        $t->setPrimaryKey(['scope_key','operation_id']);$t->addIndex(['phase','expires_at'],'mcp_mail_setup_expiry');
        $slots=$s->createTable('mcp_mail_probe_slots');
        $slots->addColumn('scope_key',Types::STRING,['length'=>64,'notnull'=>true]);
        $slots->addColumn('slot',Types::INTEGER,['notnull'=>true]);
        $slots->addColumn('operation_id',Types::STRING,['length'=>128,'notnull'=>true]);
        $slots->addColumn('expires_at',Types::BIGINT,['notnull'=>true]);
        $slots->setPrimaryKey(['scope_key','slot']);$slots->addUniqueIndex(['scope_key','operation_id'],'mcp_mail_probe_once');
        $slots->addIndex(['expires_at'],'mcp_mail_probe_expiry');
        return $s;
    }
}
