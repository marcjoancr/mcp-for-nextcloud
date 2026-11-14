<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput,SimpleMigrationStep};
/** Provenance lookup must remain bounded as permanent recovery evidence grows. */
final class Version050402Date20261114020000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
  $schema=$schemaClosure();$table=$schema->getTable('forms_mcp_bulk_rows');
  if(!$table->hasIndex('forms_bulk_kind_source')){$table->addIndex(['kind','source_id'],'forms_bulk_kind_source');}
  if(!$table->hasIndex('forms_bulk_kind_mapped')){$table->addIndex(['kind','mapped_id','source_id'],'forms_bulk_kind_mapped');}
  return $schema;
 }
}
