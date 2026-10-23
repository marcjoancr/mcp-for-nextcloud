<?php
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/AclMapper.php
// SHA-256: 942e6d47e582545901d8342451bfb7f6de52bf1857dec8ece12b628600a7f338


/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
/** @template-extends DeckMapper<Acl> */
class AclMapper extends DeckMapper implements IPermissionMapper
{
    public function __construct(IDBConnection $db)
    {
    }
    /**
     * @return Acl[]
     * @throws \OCP\DB\Exception
     */
    public function findAll(int $boardId, ?int $limit = null, ?int $offset = null)
    {
    }
    public function findIn(array $boardIds, ?int $limit = null, ?int $offset = null): array
    {
    }
    /**
     * @throws \OCP\DB\Exception
     */
    public function isOwner(string $userId, int $id): bool
    {
    }
    public function findBoardId(int $id): ?int
    {
    }
    /**
     * @param int $type
     * @param string $participant
     * @return Acl[]
     * @throws \OCP\DB\Exception
     */
    public function findByParticipant(int $type, string $participant): array
    {
    }
    public function findParticipantFromBoard(int $boardId, int $type, string $participant): ?Acl
    {
    }
    /**
     * @throws \OCP\DB\Exception
     */
    public function deleteParticipantFromBoard(int $boardId, int $type, string $participant): void
    {
    }
    public function findByType(int $type): array
    {
    }
}
