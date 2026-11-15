<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Migration;
use Closure;use OCP\DB\{ISchemaWrapper,Types};use OCP\Migration\{IOutput,SimpleMigrationStep};
/** Forms-owned history only; activation epoch is set by the private installation transaction. */
final class Version050402Date20261115020000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
        $schema=$schemaClosure();
        if (!$schema->hasTable('forms_mcp_group_history')) {
            $t=$schema->createTable('forms_mcp_group_history');
            foreach (['gid_key'=>64,'gid'=>255,'epoch'=>64,'generation'=>64,'state'=>8] as $name=>$length) {$t->addColumn($name,Types::STRING,['length'=>$length]);}
            $t->setPrimaryKey(['gid_key']);
        }
        if (!$schema->hasTable('forms_mcp_group_epoch')) {
            $t=$schema->createTable('forms_mcp_group_epoch');$t->addColumn('id',Types::INTEGER);$t->addColumn('epoch',Types::STRING,['length'=>64]);$t->setPrimaryKey(['id']);
        }
        return $schema;
    }
}
