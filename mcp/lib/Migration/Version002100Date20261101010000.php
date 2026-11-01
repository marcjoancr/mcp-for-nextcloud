<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Migration;

use Closure;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Grants\LegacyToolCatalog;
use OCA\Mcp\OAuth\ConnectionToolsStore;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Adds per-connection tool choices and freezes the access of credentials issued by the previous model. */
class Version002100Date20261101010000 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $db, private GrantPolicy $policy) {}

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $changed = false;
        if ($schema->hasTable('mcp_oauth_codes') && !$schema->getTable('mcp_oauth_codes')->hasColumn('tool_names')) {
            $schema->getTable('mcp_oauth_codes')->addColumn('tool_names', Types::TEXT, ['notnull' => false]);
            $changed = true;
        }
        if ($schema->hasTable('mcp_oauth_codes') && !$schema->getTable('mcp_oauth_codes')->hasColumn('tool_variants')) {
            $schema->getTable('mcp_oauth_codes')->addColumn('tool_variants', Types::TEXT, ['notnull' => false]);
            $changed = true;
        }
        if (!$schema->hasTable(ConnectionToolsStore::TABLE)) {
            $table = $schema->createTable(ConnectionToolsStore::TABLE);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('connection_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('tool_names', Types::TEXT, ['notnull' => true]);
            $table->addColumn('tool_variants', Types::TEXT, ['notnull' => true]);
            $table->addColumn('revision', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['user_id', 'connection_id']);
            $changed = true;
        }
        return $changed ? $schema : null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $definitions = LegacyToolCatalog::DEFINITIONS;
        foreach ($this->rows('mcp_oauth_tokens') as $row) {
            $uid = (string)$row['user_id'];
            $id = (int)$row['id'];
            $snapshot = $this->snapshot($uid, $definitions);
            $existing = $this->selection($uid, $id);
            if ($existing === null) {
                $this->insert($uid, $id, $snapshot['tools'], $snapshot['variants']);
            } elseif ($existing !== $snapshot) {
                throw new \UnexpectedValueException('OAuth connection tool snapshot differs from the frozen legacy policy');
            }
        }
        // Preserve Basic/session selections for every account with the stored connected flag, even when the account is
        // temporarily ineligible and therefore cannot currently authenticate.
        foreach ($this->policy->flaggedUsers(GrantPolicy::CONNECTED_KEY) as $uid) {
            $snapshot = $this->snapshot($uid, $definitions);
            $existing = $this->selection($uid, ConnectionToolsStore::BASIC_PROFILE);
            if ($existing === null) {
                $this->insert($uid, ConnectionToolsStore::BASIC_PROFILE, $snapshot['tools'], $snapshot['variants']);
            } elseif ($existing !== $snapshot) {
                throw new \UnexpectedValueException('Basic connection tool snapshot differs from the frozen legacy policy');
            }
        }
        foreach ($this->codeRows() as $row) {
            $uid = (string)$row['user_id'];
            $selection = $this->snapshot($uid, $definitions);
            if ($row['tool_names'] === null && $row['tool_variants'] === null) {
                $qb = $this->db->getQueryBuilder();
                $qb->update('mcp_oauth_codes')->set('tool_names', $qb->createNamedParameter(json_encode($selection['tools'], JSON_THROW_ON_ERROR)))
                    ->set('tool_variants', $qb->createNamedParameter(json_encode($selection['variants'], JSON_THROW_ON_ERROR)))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->isNull('tool_names'))
                    ->executeStatement();
            } elseif (self::decodeSelection((string)$row['tool_names'], (string)$row['tool_variants']) !== $selection) {
                throw new \UnexpectedValueException('Pending OAuth code tool snapshot differs from the frozen legacy policy');
            }
        }
        $output->info('Snapshotted tool access for existing OAuth connections and pending authorization codes');
    }

    /** @param array<string,array<string,mixed>> $definitions @return array{tools:list<string>,variants:array<string,list<string>>} */
    private function snapshot(string $uid, array $definitions): array {
        return $this->policy->within($uid, function () use ($uid, $definitions): array {
            $allowed = [];
            $variants = [];
            foreach ($definitions as $name => $definition) {
                $module = $definition['module'] ?? null;
                $operation = $definition['operation'] ?? null;
                if (!is_string($module) || !is_string($operation)) {
                    throw new \UnexpectedValueException('Invalid tool definition during connection migration');
                }
                $alternatives = $definition['grantAnyOf'] ?? [$operation];
                $grantedVariants = [];
                foreach ($alternatives as $alternative) {
                    if ($this->policy->granted($uid, $module, $alternative)) {
                        $grantedVariants[] = $alternative;
                    }
                }
                $granted = $grantedVariants !== [];
                foreach ($definition['grantAllOf'] ?? [] as $requiredModule => $requiredOperations) {
                    foreach ($requiredOperations as $requiredOperation) {
                        $granted = $granted && $this->policy->granted($uid, $requiredModule, $requiredOperation);
                    }
                }
                if ($granted) {
                    $allowed[] = $name;
                    if (isset($definition['grantAnyOf'])) {
                        $variants[$name] = $grantedVariants;
                    }
                }
            }
            sort($allowed, SORT_STRING);
            ksort($variants, SORT_STRING);
            return ['tools' => $allowed, 'variants' => $variants];
        });
    }

    /** @return iterable<array<string,mixed>> */
    private function rows(string $table): iterable {
        $lastId = 0;
        do {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id', 'user_id')->from($table)
                ->where($qb->expr()->gt('id', $qb->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
                ->orderBy('id', 'ASC')->setMaxResults(200)->executeQuery();
            try {
                $page = $result->fetchAll();
            } finally {
                $result->closeCursor();
            }
            foreach ($page as $row) {
                $lastId = (int)$row['id'];
                yield $row;
            }
        } while (count($page) === 200);
    }

    /** @return iterable<array<string,mixed>> */
    private function codeRows(): iterable {
        $lastId = 0;
        do {
            $qb = $this->db->getQueryBuilder();
            $result = $qb->select('id', 'user_id', 'tool_names', 'tool_variants')->from('mcp_oauth_codes')
                ->where($qb->expr()->gt('id', $qb->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
                ->orderBy('id', 'ASC')->setMaxResults(200)->executeQuery();
            try {
                $page = $result->fetchAll();
            } finally {
                $result->closeCursor();
            }
            foreach ($page as $row) {
                $lastId = (int)$row['id'];
                yield $row;
            }
        } while (count($page) === 200);
    }

    /** @return array{tools:list<string>,variants:array<string,list<string>>}|null */
    private function selection(string $uid, int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('tool_names', 'tool_variants')->from(ConnectionToolsStore::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('connection_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)->executeQuery();
        try {
            $row = $result->fetch();
            return $row === false ? null : self::decodeSelection((string)$row['tool_names'], (string)$row['tool_variants']);
        } finally {
            $result->closeCursor();
        }
    }

    /** @return array{tools:list<string>,variants:array<string,list<string>>} */
    private static function decodeSelection(string $toolsJson, string $variantsJson): array {
        $tools = json_decode($toolsJson, true);
        $variants = json_decode($variantsJson, true);
        if (!is_array($tools) || !array_is_list($tools) || !is_array($variants) || (array_is_list($variants) && $variants !== [])) {
            throw new \UnexpectedValueException('Invalid stored connection tool snapshot');
        }
        foreach ($tools as $tool) {
            if (!is_string($tool)) {
                throw new \UnexpectedValueException('Invalid stored connection tool snapshot');
            }
        }
        foreach ($variants as $tool => $operations) {
            if (!is_string($tool) || !in_array($tool, $tools, true) || !is_array($operations) || !array_is_list($operations)) {
                throw new \UnexpectedValueException('Invalid stored connection tool snapshot');
            }
            foreach ($operations as $operation) {
                if (!is_string($operation)) {
                    throw new \UnexpectedValueException('Invalid stored connection tool snapshot');
                }
            }
            $variants[$tool] = array_values(array_unique($operations));
            sort($variants[$tool], SORT_STRING);
        }
        sort($tools, SORT_STRING);
        ksort($variants, SORT_STRING);
        return ['tools' => $tools, 'variants' => $variants];
    }

    /** @param list<string> $tools */
    private function insert(string $uid, int $id, array $tools, array $variants): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(ConnectionToolsStore::TABLE)
            ->setValue('user_id', $qb->createNamedParameter($uid))
            ->setValue('connection_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            ->setValue('tool_names', $qb->createNamedParameter(json_encode($tools, JSON_THROW_ON_ERROR)))
            ->setValue('tool_variants', $qb->createNamedParameter(json_encode($variants, JSON_THROW_ON_ERROR)))
            ->setValue('revision', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
            ->executeStatement();
    }
}
