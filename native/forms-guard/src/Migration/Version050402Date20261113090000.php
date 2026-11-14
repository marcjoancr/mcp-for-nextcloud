<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Migration;
use Closure;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{IOutput,SimpleMigrationStep};
final class Version050402Date20261113090000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
  $s=$schemaClosure();if(!$s->hasTable('forms_mcp_bulk_epoch')){$t=$s->createTable('forms_mcp_bulk_epoch');$t->addColumn('form_id',Types::BIGINT,[]);$t->addColumn('revision',Types::BIGINT,['default'=>0]);$t->setPrimaryKey(['form_id']);}return $s;
 }
}
