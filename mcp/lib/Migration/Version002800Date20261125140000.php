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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version002800Date20261125140000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('mcp_mail_owned_drafts')) {
			$table = $schema->createTable('mcp_mail_owned_drafts');
			$table->addColumn('actor_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('actor_incarnation', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('version', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('payload', Types::TEXT, ['notnull' => true]);
			$table->setPrimaryKey(['actor_uid']);
			$table->addIndex(['updated_at'], 'mcp_mail_draft_updated');
		}
		return $schema;
	}
}
