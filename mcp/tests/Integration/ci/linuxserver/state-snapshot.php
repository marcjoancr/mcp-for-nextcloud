<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
use OCP\{Server, IConfig, IDBConnection};
function savePrivate(string $path, array $value): void {
    $bytes = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    if (file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, 0600)) throw new RuntimeException('Private state write failed');
}
function snapshot(): array {
    $db = Server::get(IDBConnection::class); $config = Server::get(IConfig::class);
    $names = $db->executeQuery("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND (table_name LIKE 'oc_mcp_%' OR table_name LIKE 'oc_deck_%' OR table_name LIKE 'oc_talk_%' OR table_name IN ('oc_migrations','oc_preferences','oc_calendars','oc_calendarobjects','oc_addressbooks','oc_cards')) ORDER BY table_name")->fetchFirstColumn();
    $columns = $db->executeQuery("SELECT table_name,column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema = 'public' AND (table_name LIKE 'oc_mcp_%' OR table_name IN ('oc_migrations','oc_preferences')) ORDER BY table_name,ordinal_position")->fetchAll();
    $tables = [];
    foreach ($names as $name) {
        if (!preg_match('/^oc_[a-z_]+$/D', $name)) throw new RuntimeException('Unexpected table name');
        $table = substr($name, 3); $qb = $db->getQueryBuilder(); $qb->select('*')->from($table);
        if ($table === 'migrations') $qb->where($qb->expr()->eq('app', $qb->createNamedParameter('mcp')));
        if ($table === 'preferences') $qb->where($qb->expr()->eq('appid', $qb->createNamedParameter('mcp')));
        $rows = $qb->executeQuery()->fetchAll();
        foreach ($rows as &$row) { ksort($row); foreach ($row as &$value) $value = databaseValue($value); unset($value); } unset($row);
        usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b))); $tables[$table] = $rows;
    }
    $settings = [];
    foreach ($config->getAppKeys('mcp') as $key) if ($key !== 'installed_version') $settings[$key] = $config->getAppValue('mcp', $key);
    ksort($settings);
    $files = [];
    $root = Server::get(\OCP\Files\IRootFolder::class)->getUserFolder('l2-owner');
    $walk = function ($node) use (&$walk, &$files, $root): void {
        if ($node instanceof \OCP\Files\Folder) { foreach ($node->getDirectoryListing() as $child) $walk($child); }
        else $files[$root->getRelativePath($node->getPath())] = ['id' => $node->getId(), 'bytes' => strlen($node->getContent()), 'sha256' => hash('sha256', $node->getContent())];
    };
    $walk($root); ksort($files);
    return ['schema' => $columns, 'tables' => $tables, 'settings' => $settings, 'sourceOffer' => $config->getSystemValue('mcp_source_offer'), 'files' => $files];
}
