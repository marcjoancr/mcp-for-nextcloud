<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Migration;
use OCP\DB\{ISchemaWrapper,Types};
use OCP\Migration\{SimpleMigrationStep,IOutput};
use OCP\{IDBConnection,IConfig};
/** Empty derived stores: no mailbox scan, content fetch or backfill on upgrade. */
final class Version5125Date20261111030000 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $db,private IConfig $config){}
    private static function idType(object $type):string {return match(true){$type instanceof \BackedEnum&&in_array($type->value,[Types::BIGINT,Types::INTEGER],true)=>$type->value,$type instanceof \Doctrine\DBAL\Types\BigIntType=>Types::BIGINT,$type instanceof \Doctrine\DBAL\Types\IntegerType=>Types::INTEGER,default=>throw new \RuntimeException('Unsupported native identity column.')};}
    public function changeSchema(IOutput $output,\Closure $schemaClosure,array $options):?ISchemaWrapper {
        $s=$schemaClosure();if(!$s->hasTable('mail_mcp_body_index')){
            $t=$s->createTable('mail_mcp_body_index');
            foreach(['message_id'=>'mail_messages','account_id'=>'mail_accounts','mailbox_id'=>'mail_mailboxes'] as $column=>$parent){$type=self::idType($s->getTable($parent)->getColumn('id')->getType());$t->addColumn($column,$type,['notnull'=>true]);$t->addForeignKeyConstraint($s->getTable($parent)->getName(),[$column],['id'],['onDelete'=>'CASCADE']);}
            foreach(['user_id'=>255,'imap_uid'=>10,'generation'=>10,'profile'=>64,'content_revision'=>64,'policy_version'=>32,'coverage_state'=>24] as $name=>$length)$t->addColumn($name,Types::STRING,['notnull'=>true,'length'=>$length]);
            $t->addColumn('auth_revision',Types::BIGINT,['notnull'=>true]);$t->addColumn('body_text',Types::TEXT,['notnull'=>true]);$t->addColumn('indexed_at',Types::BIGINT,['notnull'=>true]);$t->setPrimaryKey(['message_id']);$t->addIndex(['user_id','mailbox_id','message_id'],'mail_mcp_body_owner');
        }
        if(!$s->hasTable('mail_mcp_draft_copies')){
            $t=$s->createTable('mail_mcp_draft_copies');foreach(['operation_key'=>64,'user_id'=>255,'approval_digest'=>64,'state'=>16,'source_refs'=>2048] as $name=>$length)$t->addColumn($name,Types::STRING,['notnull'=>true,'length'=>$length]);
            $t->addColumn('target_account_id',self::idType($s->getTable('mail_accounts')->getColumn('id')->getType()),['notnull'=>true]);$t->addColumn('local_draft_id',self::idType($s->getTable('mail_local_messages')->getColumn('id')->getType()),['notnull'=>false]);$t->addColumn('created_at',Types::BIGINT,['notnull'=>true]);$t->setPrimaryKey(['operation_key']);
            $t->addForeignKeyConstraint($s->getTable('mail_accounts')->getName(),['target_account_id'],['id'],['onDelete'=>'CASCADE']);
            // A deleted local copy must not make the stable operation key reusable.
            $t->addForeignKeyConstraint($s->getTable('mail_local_messages')->getName(),['local_draft_id'],['id'],['onDelete'=>'SET NULL']);$t->addIndex(['user_id','target_account_id'],'mail_mcp_copy_owner');
        }return $s;
    }
    public function postSchemaChange(IOutput $output,\Closure $schemaClosure,array $options):void {
        if($this->db->getDatabaseProvider()!==IDBConnection::PLATFORM_POSTGRES)return;
        $prefix=$this->config->getSystemValueString('dbtableprefix','oc_');if(preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$prefix)!==1)throw new \RuntimeException('Unsupported native table prefix.');
        $this->db->executeStatement('CREATE INDEX IF NOT EXISTS "'.$prefix.'mail_mcp_body_fts" ON "'.$prefix.'mail_mcp_body_index" USING GIN (to_tsvector(\'simple\'::regconfig, body_text)) WHERE coverage_state = \'complete\'');
    }
}
