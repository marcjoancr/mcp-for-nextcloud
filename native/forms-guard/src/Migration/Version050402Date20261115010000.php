<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Migration;
use Closure;use OCP\DB\{ISchemaWrapper,Types};use OCP\Migration\{IOutput,SimpleMigrationStep};
final class Version050402Date20261115010000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
  $s=$schemaClosure();if(!$s->hasTable('forms_mcp_incarnations')){$t=$s->createTable('forms_mcp_incarnations');$t->addColumn('uid_hash',Types::STRING,['length'=>64]);$t->addColumn('uid',Types::STRING,['length'=>255]);$t->addColumn('generation',Types::STRING,['length'=>64]);$t->addColumn('legacy_actor',Types::STRING,['length'=>64,'notnull'=>false]);$t->setPrimaryKey(['uid_hash']);}return $s;
 }
}
