<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Preserve native result admission without materializing every prior submission ID. */
final class ReadSubmissionMapper extends \OCA\Forms\Db\SubmissionMapper {
    public function countSubmissions(int $formId,?string $userId=null,?string $searchString=null):int {
        if($searchString!==null&&$searchString!=='') {return parent::countSubmissions($formId,$userId,$searchString);}
        // Native no-search GROUP BY(id) counts unique primary keys without a join; SQL COUNT is equivalent.
        $qb=$this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))->from($this->getTableName())->where($qb->expr()->eq('form_id',$qb->createNamedParameter($formId,IQueryBuilder::PARAM_INT)));
        if($userId!==null){$qb->andWhere($qb->expr()->eq('user_id',$qb->createNamedParameter($userId)));}
        $result=$qb->executeQuery();try{$count=$result->fetchOne();}finally{$result->closeCursor();}
        if($count===false){throw new \RuntimeException('Native submission count is unavailable');}
        return (int)$count;
    }
}
