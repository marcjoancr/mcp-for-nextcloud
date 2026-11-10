<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use Doctrine\DBAL\Types\BooleanType;
use OCP\{IDBConnection,IConfig};
/** A source hash cannot prove that persisted draft-only protection exists. */
class MailDraftStorage {
    public function __construct(private IDBConnection $db,private IConfig $config){}
    public function verified():bool {
        try {
            if(!in_array($this->db->getDatabaseProvider(),[IDBConnection::PLATFORM_MYSQL,IDBConnection::PLATFORM_POSTGRES],true))return false;
            (new MailJournalStorage($this->db,$this->config))->requireTables(['mail_accounts','mail_local_messages','mail_recipients','mail_attachments']);
            $schema=$this->db->createSchema();$table=$schema->getTable($this->config->getSystemValueString('dbtableprefix','oc_').'mail_local_messages');
            if(!$table->hasColumn('mcp_draft_only'))return false;
            $column=$table->getColumn('mcp_draft_only');
            return $column->getType() instanceof BooleanType && $column->getNotnull() && in_array($column->getDefault(),[false,0,'0'],true);
        }catch(\Throwable){return false;}
    }
}
