<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Db;
use OCP\IDBConnection;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
/** Five expiring CAS slots per actor/account, shared by all of that actor's connections. */
class MailProbeSlotsMapper {
    public function __construct(private IDBConnection $db){}
    public function reserve(string $scope,string $operation,int $now): bool {
        for($slot=0;$slot<5;$slot++){
            $q=$this->db->getQueryBuilder();
            try {
                $n=$q->update('mcp_mail_probe_slots')->set('operation_id',$q->createNamedParameter($operation))->set('expires_at',$q->createNamedParameter($now+60,IQueryBuilder::PARAM_INT))
                    ->where($q->expr()->eq('scope_key',$q->createNamedParameter($scope)))->andWhere($q->expr()->eq('slot',$q->createNamedParameter($slot,IQueryBuilder::PARAM_INT)))
                    ->andWhere($q->expr()->lte('expires_at',$q->createNamedParameter($now,IQueryBuilder::PARAM_INT)))->executeStatement();
                if($n===1)return true;
                $q=$this->db->getQueryBuilder();$q->insert('mcp_mail_probe_slots');
                foreach(['scope_key'=>$scope,'slot'=>$slot,'operation_id'=>$operation,'expires_at'=>$now+60] as $key=>$value)$q->setValue($key,$q->createNamedParameter($value,is_int($value)?IQueryBuilder::PARAM_INT:IQueryBuilder::PARAM_STR));
                $q->executeStatement();return true;
            }catch(Exception $e){if($e->getReason()!==Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION)throw $e;}
        }
        return false;
    }
    public function compact(int $before): void {
        $q=$this->db->getQueryBuilder();$q->delete('mcp_mail_probe_slots')->where($q->expr()->lt('expires_at',$q->createNamedParameter($before,IQueryBuilder::PARAM_INT)))->executeStatement();
    }
}
