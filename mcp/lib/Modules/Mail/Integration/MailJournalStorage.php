<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCP\{IDBConnection,IConfig};
class MailJournalStorage {
    public function __construct(private IDBConnection $db,private IConfig $config){}
    public function requireTables(array $tables):void {
        if($this->config->getSystemValue('dbsharding',[])!==[]||$this->config->getSystemValue('dbreplica',[])!==[])throw new BeforeEffectFailure('Local Mail transactions require unsharded primary storage.');
        if($this->db->getDatabaseProvider()===IDBConnection::PLATFORM_POSTGRES&&!\OCA\Mail\Db\PgDirectConnection::verified($this->db,$this->config))throw new BeforeEffectFailure('Local Mail transactions require a directly connected PostgreSQL primary.');
        $schema=$this->db->createSchema();$prefix=$this->config->getSystemValueString('dbtableprefix','oc_');
        foreach($tables as $name){
            $table=$schema->getTable($prefix.$name);
            if($this->db->getDatabaseProvider()===IDBConnection::PLATFORM_MYSQL&&strtolower((string)($table->getOptions()['engine']??''))!=='innodb')throw new BeforeEffectFailure('All participating Mail/journal tables must support transactions.');
        }
    }
}
