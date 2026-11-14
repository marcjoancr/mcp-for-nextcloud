<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Migration;
use Closure;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{IOutput,SimpleMigrationStep};
final class Version050402Date20261113080000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options): ?ISchemaWrapper {
  $s=$schemaClosure();
  if(!$s->hasTable('forms_mcp_bulk')){
   $t=$s->createTable('forms_mcp_bulk');$t->addColumn('id',Types::STRING,['length'=>64]);$t->addColumn('operation_key',Types::STRING,['length'=>128]);$t->addColumn('actor_hash',Types::STRING,['length'=>64]);$t->addColumn('payload',Types::TEXT,[]);$t->addColumn('bytes',Types::BIGINT,['default'=>0]);$t->setPrimaryKey(['id']);$t->addUniqueIndex(['operation_key'],'forms_bulk_key');
  }
  if(!$s->hasTable('forms_mcp_bulk_rows')){
   $t=$s->createTable('forms_mcp_bulk_rows');$t->addColumn('bulk_id',Types::STRING,['length'=>64]);$t->addColumn('kind',Types::STRING,['length'=>20]);$t->addColumn('source_id',Types::BIGINT,[]);$t->addColumn('payload',Types::TEXT,[]);$t->addColumn('digest',Types::STRING,['length'=>64]);$t->addColumn('mapped_id',Types::BIGINT,['default'=>0]);$t->setPrimaryKey(['bulk_id','kind','source_id']);
  }
  if(!$s->hasTable('forms_mcp_bulk_steps')){
   $t=$s->createTable('forms_mcp_bulk_steps');$t->addColumn('step_key',Types::STRING,['length'=>128]);$t->addColumn('bulk_id',Types::STRING,['length'=>64]);$t->addColumn('receipt',Types::TEXT,[]);$t->setPrimaryKey(['step_key']);
  }
  return $s;
 }
}
