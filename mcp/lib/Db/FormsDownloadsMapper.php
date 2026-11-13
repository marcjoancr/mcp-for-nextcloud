<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;
use OCA\Mcp\Modules\Forms\Exports\FormsDownloadStore;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
final class FormsDownloadsMapper implements FormsDownloadStore {
    public function __construct(private IDBConnection $db) {}
    public function create(array $row):bool {
        $q=$this->db->getQueryBuilder();$q->insert('mcp_forms_downloads');
        foreach($row as $key=>$value)$q->setValue($key,$q->createNamedParameter(is_bool($value)?(int)$value:$value,is_int($value)||is_bool($value)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR));
        return $q->executeStatement()===1;
    }
    public function get(string $hash):?array {
        $q=$this->db->getQueryBuilder();$r=$q->select('*')->from('mcp_forms_downloads')->where($q->expr()->eq('token_hash',$q->createNamedParameter($hash)))->executeQuery();
        try{$row=$r->fetch();}finally{$r->closeCursor();}
        if(!$row)return null;$row['used']=(bool)$row['used'];$row['expires_at']=(int)$row['expires_at'];return $row;
    }
    public function consume(array $expected,int $now):bool {
        $q=$this->db->getQueryBuilder();return $q->update('mcp_forms_downloads')->set('used',$q->createNamedParameter(1,IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('token_hash',$q->createNamedParameter($expected['token_hash'])))
            ->andWhere($q->expr()->eq('job_id',$q->createNamedParameter($expected['job_id'])))
            ->andWhere($q->expr()->eq('used',$q->createNamedParameter(0,IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gt('expires_at',$q->createNamedParameter($now,IQueryBuilder::PARAM_INT)))->executeStatement()===1;
    }
}
