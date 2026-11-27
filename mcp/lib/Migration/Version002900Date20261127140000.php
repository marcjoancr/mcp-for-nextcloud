<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version002900Date20261127140000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('mcp_mail_owned_attempts')) {
			return null;
		}
		$table = $schema->createTable('mcp_mail_owned_attempts');
		$table->addColumn('draft_id', Types::STRING, ['length' => 32, 'notnull' => true]);
		$table->addColumn('actor_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('actor_incarnation', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('account_id', Types::INTEGER, ['notnull' => true]);
		$table->addColumn('draft_revision', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('send_state', Types::STRING, ['length' => 32, 'notnull' => true]);
		$table->addColumn('recorded_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('payload', Types::TEXT, ['notnull' => true]);
		$table->setPrimaryKey(['draft_id']);
		return $schema;
	}
}
