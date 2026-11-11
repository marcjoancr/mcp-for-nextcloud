<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Migration;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{SimpleMigrationStep,IOutput};
final class Version5125Date20261111120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
        $s=$schemaClosure();if($s->hasTable('mail_mcp_snoozes'))return $s;$t=$s->createTable('mail_mcp_snoozes');
        foreach(['id'=>64,'user_id'=>255,'operation_key'=>64,'approval'=>64,'profile_revision'=>64,'roles_revision'=>64,'timezone'=>64,'state'=>16,'placement_phase'=>24,'wake_phase'=>24,'source_generation'=>10,'source_uid'=>10,'snooze_generation'=>10,'current_generation'=>10,'current_uid'=>10,'source_name'=>255,'snooze_name'=>255,'reason'=>48]as$n=>$length)$t->addColumn($n,Types::STRING,['notnull'=>true,'length'=>$length]);
        foreach(['account_id','connection_id','revision','created_at','wake_at','source_mailbox_id','source_message_id','snooze_mailbox_id','current_mailbox_id']as$n)$t->addColumn($n,Types::BIGINT,['notnull'=>true]);
        $t->addColumn('active_source',Types::STRING,['notnull'=>false,'length'=>64,'default'=>null]);
        foreach(['placement_request','wake_request','placement_proof','wake_proof']as$n)$t->addColumn($n,Types::TEXT,['notnull'=>true,'length'=>65535]);
        $t->setPrimaryKey(['id']);$t->addUniqueIndex(['user_id','operation_key'],'mail_snooze_operation');$t->addUniqueIndex(['active_source'],'mail_snooze_active');
        $t->addIndex(['state','wake_at'],'mail_snooze_due');$t->addIndex(['state','id'],'mail_snooze_audit');$t->addIndex(['account_id','state'],'mail_snooze_account');return $s;
    }
}
