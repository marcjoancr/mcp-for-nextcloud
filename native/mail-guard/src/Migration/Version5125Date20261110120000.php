<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Migration;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{SimpleMigrationStep,IOutput};
final class Version5125Date20261110120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
        $schema=$schemaClosure();$messages=$schema->getTable('mail_local_messages');
        if(!$messages->hasColumn('mcp_draft_only'))$messages->addColumn('mcp_draft_only',Types::BOOLEAN,['notnull'=>true,'default'=>false]);
        return $schema;
    }
}
