<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Migration;
use Closure;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{IOutput,SimpleMigrationStep};
final class Version050402Date20261114010000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options):?ISchemaWrapper {
  $s=$schemaClosure();if(!$s->hasTable('forms_mcp_comment_auth')){$t=$s->createTable('forms_mcp_comment_auth');$t->addColumn('comment_id',Types::BIGINT,[]);$t->addColumn('form_id',Types::BIGINT,[]);$t->addColumn('payload',Types::TEXT,[]);$t->setPrimaryKey(['comment_id']);}return $s;
 }
}
