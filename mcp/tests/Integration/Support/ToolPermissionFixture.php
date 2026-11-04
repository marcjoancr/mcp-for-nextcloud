<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Support;

use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\OAuth\ConnectionToolsStore;
use OCP\IDBConnection;
use OCP\Server;
use RuntimeException;

/** Assign per-tool integration-test permissions through the real preview/commit admin contract. */
final class ToolPermissionFixture {
    /** @param array<string, string> $changes tool name => allow, deny or inherit */
    public static function assign(string $uid, array $changes): void {
        $headers = [
            'Authorization: ' . LoginFactory::basic('admin', false)->authorization(),
            'OCS-APIRequest: true',
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $base = Http::BASE . '/index.php/apps/mcp/settings/tool-permissions';
        $query = http_build_query(['kind' => 'user', 'subject' => $uid]);
        $detail = Http::request('GET', $base . '?' . $query, $headers);
        self::expect($detail, 200, 'read tool permissions');
        $revision = json_decode($detail['body'], true, 512, JSON_THROW_ON_ERROR)['revision'] ?? null;
        if (!is_string($revision)) {
            throw new RuntimeException('Tool permission detail omitted its revision');
        }
        $body = ['kind' => 'user', 'subject' => $uid, 'revision' => $revision, 'changes' => $changes];
        $preview = Http::request('POST', $base, $headers, json_encode($body + ['preview' => true], JSON_THROW_ON_ERROR));
        self::expect($preview, 200, 'preview tool permissions');
        $previewId = json_decode($preview['body'], true, 512, JSON_THROW_ON_ERROR)['previewId'] ?? null;
        if (!is_string($previewId)) {
            throw new RuntimeException('Tool permission preview omitted its receipt');
        }
        $commit = Http::request('POST', $base, $headers,
            json_encode($body + ['preview' => false, 'previewId' => $previewId], JSON_THROW_ON_ERROR));
        self::expect($commit, 200, 'commit tool permissions');
    }

    /** @param list<string> $uids @param list<string> $tools @return array{grants:array<string,array<string,?string>>,profiles:array<string,array{present:bool,tools:list<string>,variants:array<string,list<string>>}>} */
    public static function capture(array $uids, array $tools): array {
        $mapper = Server::get(ToolGrantsMapper::class);
        $stored = $mapper->forPrincipals(ToolGrantsMapper::USER, $uids);
        $grants = [];
        $profiles = [];
        $store = Server::get(ConnectionToolsStore::class);
        $db = Server::get(IDBConnection::class);
        foreach ($uids as $uid) {
            foreach ($tools as $tool) {
                $grants[$uid][$tool] = $stored[$uid][$tool] ?? null;
            }
            $qb = $db->getQueryBuilder();
            $result = $qb->select('tool_names')->from(ConnectionToolsStore::TABLE)
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('connection_id', $qb->createNamedParameter(ConnectionToolsStore::BASIC_PROFILE, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeQuery();
            try {
                $present = $result->fetchOne() !== false;
            } finally {
                $result->closeCursor();
            }
            $current = $store->get($uid, ConnectionToolsStore::BASIC_PROFILE);
            $profiles[$uid] = ['present' => $present, 'tools' => $current['tools'] ?? [], 'variants' => $current['variants'] ?? []];
        }
        return compact('grants', 'profiles');
    }

    /** @param array{grants:array<string,array<string,?string>>,profiles:array<string,array{present:bool,tools:list<string>,variants:array<string,list<string>>}>} $snapshot */
    public static function restore(array $snapshot): void {
        $uids = array_keys($snapshot['grants']);
        $mapper = Server::get(ToolGrantsMapper::class);
        Server::get(PolicyTransaction::class)->runInternal($uids, static fn () => null,
            static function () use ($mapper, $snapshot): bool {
                $changed = false;
                foreach ($snapshot['grants'] as $uid => $decisions) {
                    $current = $mapper->forPrincipals(ToolGrantsMapper::USER, [$uid])[$uid] ?? [];
                    foreach ($decisions as $tool => $decision) {
                        if (($current[$tool] ?? null) !== $decision) {
                            $mapper->set(ToolGrantsMapper::USER, $uid, $tool, $decision);
                            $changed = true;
                        }
                    }
                }
                return $changed;
            });
        $store = Server::get(ConnectionToolsStore::class);
        $db = Server::get(IDBConnection::class);
        foreach ($snapshot['profiles'] as $uid => $profile) {
            if ($profile['present']) {
                $current = $store->get($uid, ConnectionToolsStore::BASIC_PROFILE);
                $store->save($uid, ConnectionToolsStore::BASIC_PROFILE, $profile['tools'], $current['revision'], $profile['variants']);
                continue;
            }
            $qb = $db->getQueryBuilder();
            $qb->delete(ConnectionToolsStore::TABLE)
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('connection_id', $qb->createNamedParameter(ConnectionToolsStore::BASIC_PROFILE, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();
        }
        foreach ($snapshot['profiles'] as $uid => $profile) {
            $current = $store->get($uid, ConnectionToolsStore::BASIC_PROFILE);
            $qb = $db->getQueryBuilder();
            $result = $qb->select('tool_names')->from(ConnectionToolsStore::TABLE)
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('connection_id', $qb->createNamedParameter(ConnectionToolsStore::BASIC_PROFILE, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeQuery();
            try {
                $present = $result->fetchOne() !== false;
            } finally {
                $result->closeCursor();
            }
            if ($present !== $profile['present'] || ($profile['present'] && ($current['tools'] !== $profile['tools'] || $current['variants'] !== $profile['variants']))) {
                throw new RuntimeException('Could not restore the original Basic connection profile for ' . $uid);
            }
        }
        foreach ($snapshot['grants'] as $uid => $decisions) {
            $restored = Server::get(ToolGrantsMapper::class)->forPrincipals(ToolGrantsMapper::USER, [$uid])[$uid] ?? [];
            foreach ($decisions as $tool => $decision) {
                if (($restored[$tool] ?? null) !== $decision) {
                    throw new RuntimeException('Could not restore the original tool grant for ' . $uid . '/' . $tool);
                }
            }
        }
    }

    private static function expect(array $response, int $status, string $action): void {
        if (($response['status'] ?? null) !== $status) {
            throw new RuntimeException(sprintf('Could not %s: HTTP %s: %s', $action, $response['status'] ?? 'unknown', $response['body'] ?? ''));
        }
    }
}
