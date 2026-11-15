<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Closed configuration evidence; native getter migration/repair methods are never invoked. */
final class CircleReadConfig {
    public const KEYS = ['loopback_tmp_id','loopback_cloud_id','force_nc_base','loopback_tmp_scheme','loopback_tmp_path','frontal_cloud_id','local_cloud_id','internal_cloud_id'];
    public static function read(\OCP\IDBConnection $db, array $system): array {
        $marks = implode(',', array_fill(0, count(self::KEYS), '?'));
        $result = $db->executeQuery('SELECT configkey,SUBSTRING(configvalue,1,8193) AS configvalue FROM *PREFIX*appconfig WHERE appid = ? AND configkey IN (' . $marks . ') LIMIT 9', ['circles', ...self::KEYS]);
        try {$rows = $result->fetchAll();} finally {$result->closeCursor();}
        if (count($rows) > count(self::KEYS)) {throw new \RuntimeException('Circle configuration row budget exceeded');}
        $values = [];
        foreach ($rows as $row) {
            if (!in_array($row['configkey'], self::KEYS, true) || isset($values[$row['configkey']])) {throw new \RuntimeException('Circle configuration row is ambiguous');}
            $values[$row['configkey']] = $row['configvalue'];
        }
        return self::resolve($values, $system);
    }
    public static function resolve(array $app, array $system): array {
        foreach ($app as $key => $value) {
            if (!in_array($key, self::KEYS, true) || !is_string($value) || strlen($value) > 8192 || preg_match('/[\x00-\x1F\x7F]/', $value)) {throw new \RuntimeException('Circle configuration is malformed');}
        }
        $systemKeys = [...array_map(static fn(string $key): string => 'circles.' . $key, self::KEYS), 'overwrite.cli.url','trusted_domains'];
        foreach ($system as $key => $value) {
            if (!in_array($key, $systemKeys, true) || ($key !== 'trusted_domains' && (!is_string($value) || strlen($value) > 8192 || preg_match('/[\x00-\x1F\x7F]/', $value)))) {throw new \RuntimeException('Circle configuration is malformed');}
        }
        $get = static fn(string $key): string => ($app[$key] ?? '') !== '' ? $app[$key] : ($system['circles.' . $key] ?? '');
        $frontal = $get('frontal_cloud_id');
        // The legacy fallback is app-config-only in the pinned native getter.
        if ($frontal === '' && ($app['local_cloud_id'] ?? '') !== '') {throw new \RuntimeException('Circle configuration requires native frontal migration');}
        $local = $get('loopback_tmp_id');
        if ($local === '') {$local = $get('loopback_cloud_id');}
        if ($local === '') {
            $url = $get('force_nc_base');
            if ($url === '') {$url = $system['overwrite.cli.url'] ?? '';}
            $parts = parse_url($url);
            if (!is_array($parts) || !isset($parts['host'], $parts['scheme']) || !in_array($parts['scheme'], ['http','https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {throw new \RuntimeException('Circle configuration has no qualified local instance');}
            if ($get('loopback_tmp_scheme') !== $parts['scheme'] || (isset($parts['path']) && $get('loopback_tmp_path') !== $parts['path'])) {throw new \RuntimeException('Circle configuration requires native loopback repair');}
            $local = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }
        foreach ([$local, $frontal, $get('internal_cloud_id')] as $instance) {
            if ($instance !== '' && (!preg_match('/^[A-Za-z0-9.\-\[\]:]+$/D', $instance) || strlen($instance) > 255)) {throw new \RuntimeException('Circle configuration instance is malformed');}
        }
        $domains = $system['trusted_domains'] ?? [];
        if (!is_array($domains) || !array_is_list($domains) || count($domains) > 100) {throw new \RuntimeException('Circle configuration domains are malformed');}
        foreach ($domains as $domain) {if (!is_string($domain) || strlen($domain) > 255 || !preg_match('/^[A-Za-z0-9.*\-\[\]:]+$/D', $domain)) {throw new \RuntimeException('Circle configuration domain is malformed');}}
        return ['local_instance' => $local, 'frontal_instance' => $frontal, 'internal_instance' => $get('internal_cloud_id'), 'trusted_domains' => array_map('strtolower', $domains)];
    }
}
