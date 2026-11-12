<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Db;
use OCP\IDBConnection;
/** Persistent tombstone: core account deletion must never remove this row. */
class ActorIncarnationsMapper {
    public const TABLE='mcp_actor_incarnations';
    public function __construct(private IDBConnection $db) {}
    public function get(string $uid): ?string {
        $q=$this->db->getQueryBuilder();$r=$q->select('actor_uid','incarnation')->from(self::TABLE)->where($q->expr()->eq('actor_key',$q->createNamedParameter(IdentityKey::user($uid))))->executeQuery();
        try{$row=$r->fetch();}finally{$r->closeCursor();}
        if($row===false)return null;
        if($row['actor_uid']!==$uid||!is_string($row['incarnation'])||preg_match('/^[a-f0-9]{64}$/D',$row['incarnation'])!==1)throw new \UnexpectedValueException('Invalid actor registry.');
        return $row['incarnation'];
    }
    /** Called only under the existing policy mutex, inside its top-level transaction. */
    public function rotate(string $uid):string {
        if(!$this->db->inTransaction())throw new \LogicException('Actor rotation requires the policy transaction.');
        $old=$this->get($uid);$token=bin2hex(random_bytes(32));$q=$this->db->getQueryBuilder();
        if($old===null)$q->insert(self::TABLE)->setValue('actor_key',$q->createNamedParameter(IdentityKey::user($uid)))->setValue('actor_uid',$q->createNamedParameter($uid))->setValue('incarnation',$q->createNamedParameter($token));
        else $q->update(self::TABLE)->set('incarnation',$q->createNamedParameter($token))->where($q->expr()->eq('actor_key',$q->createNamedParameter(IdentityKey::user($uid))));
        if($q->executeStatement()!==1)throw new \RuntimeException('Actor rotation was not persisted.');
        return $token;
    }
}
