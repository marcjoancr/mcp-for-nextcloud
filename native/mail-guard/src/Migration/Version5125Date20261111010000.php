<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Migration;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{SimpleMigrationStep,IOutput};
/** Separate upgrade step also applies to already-installed v11 development candidates. */
final class Version5125Date20261111010000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
        $schema=$schemaClosure();if(!$schema->hasTable('mail_mcp_deliveries'))return $schema;
        $table=$schema->getTable('mail_mcp_deliveries');
        if(!$table->hasIndex('mail_mcp_delivery_audit'))$table->addIndex(['state','id'],'mail_mcp_delivery_audit');
        return $schema;
    }
}
