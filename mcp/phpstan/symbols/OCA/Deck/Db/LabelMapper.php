<?php
// Analysis-only declarations; never autoload or require this file.
// Source: https://raw.githubusercontent.com/nextcloud/deck/v1.17.0/lib/Db/LabelMapper.php
// SHA-256: 8ff05d8a3e63c2219397214ac8391a40e64ff60971504a564da8ba5e103e01b7


/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Deck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
/** @template-extends DeckMapper<Label> */
class LabelMapper extends DeckMapper implements IPermissionMapper
{
    public function __construct(IDBConnection $db)
    {
    }
    /**
     * @return Label[]
     * @throws \OCP\DB\Exception
     */
    public function findAll(int $boardId, ?int $limit = null, int $offset = 0): array
    {
    }
    public function delete(Entity $entity): Entity
    {
    }
    /**
     * @return Label[]
     * @throws \OCP\DB\Exception
     */
    public function findAssignedLabelsForCard(int $cardId, ?int $limit = null, int $offset = 0): array
    {
    }
    public function findAssignedLabelsForCards(array $cardIds, ?int $limit = null, int $offset = 0): array
    {
    }
    /**
     * @return Label[]
     * @throws \OCP\DB\Exception
     */
    public function findAssignedLabelsForBoard(int $boardId, ?int $limit = null, int $offset = 0): array
    {
    }
    public function insert(Entity $entity): Entity
    {
    }
    public function update(Entity $entity, bool $updateModified = true): Entity
    {
    }
    /**
     * @return array<int, list<Label>>
     * @throws \OCP\DB\Exception
     */
    public function getAssignedLabelsForBoard(int $boardId): array
    {
    }
    /**
     * @throws \OCP\DB\Exception
     */
    public function deleteLabelAssignments(int $labelId): void
    {
    }
    /**
     * @throws \OCP\DB\Exception
     */
    public function deleteLabelAssignmentsForCard(int $cardId): void
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
}
