<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Migration;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{SimpleMigrationStep,IOutput};
final class Version5125Date20261110000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {$s=$schemaClosure();$b=$s->getTable('mail_mailboxes');if(!$b->hasColumn('refresh_snapshot'))$b->addColumn('refresh_snapshot',Types::STRING,['length'=>512,'notnull'=>false,'default'=>null]);return $s;}
}
