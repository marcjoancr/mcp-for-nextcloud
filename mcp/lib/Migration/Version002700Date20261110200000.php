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
class Version002700Date20261110200000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
        $s=$schemaClosure();$t=$s->getTable('mcp_mail_setup');
        if($t->hasColumn('native_context_id'))return null;
        $t->addColumn('native_context_id',Types::STRING,['length'=>64,'notnull'=>false,'default'=>null]);return $s;
    }
}
