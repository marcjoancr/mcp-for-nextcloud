<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Db;
use OCP\IDBConnection;
use OCP\DB\Exception;
/** Durable index of the unchanged native operation key, installed before queue admission. */
class OperationOriginsMapper {
    public function __construct(private IDBConnection $db) {}
    public function get(string $key):?array {
        if(preg_match('/^[a-f0-9]{64}$/D',$key)!==1)throw new \OCA\Mcp\Mcp\Operations\ActorRetired();
        $q=$this->db->getQueryBuilder();$r=$q->select('scope_key','operation_id')->from('mcp_operation_origins')->where($q->expr()->eq('native_key',$q->createNamedParameter($key)))->executeQuery();
        try{$row=$r->fetch();return $row===false?null:$row;}finally{$r->closeCursor();}
    }
    public function bind(string $scope,string $operation):void {
        if($this->db->inTransaction())throw new \RuntimeException('Original queue admission must be durable before native effects.');
        $key=hash('sha256',hash('sha256',$scope."\0".$operation));$expected=['scope_key'=>$scope,'operation_id'=>$operation];
        $old=$this->get($key);if($old!==null){if($old!==$expected)throw new \RuntimeException('Native origin collision.');return;}
        $q=$this->db->getQueryBuilder();
        try{$q->insert('mcp_operation_origins')->setValue('native_key',$q->createNamedParameter($key))->setValue('scope_key',$q->createNamedParameter($scope))->setValue('operation_id',$q->createNamedParameter($operation))->executeStatement();}
        catch(Exception $e){if($e->getReason()!==Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION||$this->get($key)!==$expected)throw $e;}
    }
}
