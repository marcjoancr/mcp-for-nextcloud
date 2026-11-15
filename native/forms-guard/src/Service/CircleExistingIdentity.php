<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Existing local personal identity evidence only. No target membership/permission or enrollment API. */
final class CircleExistingIdentity {
    /** Same local TYPE_USER/OWNER/CFG_SINGLE predicates as pinned CircleRequest::getSingleCircle. */
    public static function read(\OCP\IDBConnection $db, string $uid): array {
        self::uid($uid);
        $result = $db->executeQuery(
            'SELECT c.unique_id,c.creation,c.config,c.source,CASE WHEN OCTET_LENGTH(c.settings) > 1048576 THEN NULL ELSE c.settings END AS settings,m.member_id,m.circle_id,m.single_id,m.user_id,m.user_type,m.level,m.instance,m.status FROM *PREFIX*circles_circle c INNER JOIN *PREFIX*circles_member m ON m.circle_id = c.unique_id WHERE m.user_id = ? AND m.user_type = ? AND m.level >= ? AND m.instance = ? AND (c.config & 1) = 1 LIMIT 2',
            [$uid, 1, 9, '']);
        try {$rows = $result->fetchAll();} finally {$result->closeCursor();}
        return self::select($rows, $uid);
    }
    public static function select(array $rows, string $uid): array {
        self::uid($uid);
        if (count($rows) !== 1) {throw new \RuntimeException('Existing Circle identity is missing or ambiguous');}
        $rows = CircleReadData::page($rows, 2);
        $row = $rows[0];
        $keys = ['unique_id','creation','config','source','settings','member_id','circle_id','single_id','user_id','user_type','level','instance','status'];
        if (array_diff($keys, array_keys($row)) !== [] || array_diff(array_keys($row), $keys) !== []) {throw new \RuntimeException('Circle identity row shape is unqualified');}
        $id = $row['unique_id'];
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9]{31}$/D', $id) || $row['user_id'] !== $uid || $row['member_id'] !== $id || $row['single_id'] !== $id || $row['circle_id'] !== $id || $row['instance'] !== '' || $row['status'] !== 'Member'
            || self::integer($row['user_type']) !== 1 || self::integer($row['level']) !== 9 || self::integer($row['source']) !== 1 || (self::integer($row['config']) & 1) !== 1) {throw new \RuntimeException('Circle identity binding is unqualified');}
        if (!is_string($row['creation']) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $row['creation'])) {throw new \RuntimeException('Circle identity creation is unqualified');}
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $row['creation'], new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $row['creation']) {throw new \RuntimeException('Circle identity creation is unqualified');}
        return ['uid' => $uid, 'circle_id' => $id, 'member_id' => $row['member_id'], 'creation' => $row['creation'], 'source' => 1];
    }
    private static function uid(string $uid): void {
        if ($uid === '' || strlen($uid) > 255 || str_contains($uid, "\0")) {throw new \RuntimeException('Circle identity principal is malformed');}
    }
    private static function integer(mixed $value): int {
        if (is_int($value) && $value >= 0) {return $value;}
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value) || ($number = filter_var($value, FILTER_VALIDATE_INT)) === false || $number < 0) {throw new \RuntimeException('Circle identity numeric field is malformed');}
        return $number;
    }
}
