<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use Doctrine\DBAL\Types\{BigIntType,StringType,BooleanType,SmallIntType,TextType};
use OCP\{IDBConnection,IConfig};
/** The native proof and MCP completion must share primary transactional participants. */
class MailCredentialStorage {
    public function __construct(private IDBConnection $db,private IConfig $config){}
    private function columns(\Doctrine\DBAL\Schema\Table $table,array $columns):bool {
        foreach($columns as $name=>[$type,$length,$notnull]){
            if(!$table->hasColumn($name))return false;$column=$table->getColumn($name);
            if(!$column->getType() instanceof $type||$column->getNotnull()!==$notnull||($length!==null&&$column->getLength()!==$length))return false;
        }
        return true;
    }
    public function verified():bool {
        try{
            if($this->db->getDatabaseProvider()!==IDBConnection::PLATFORM_MYSQL)return false;
            (new MailJournalStorage($this->db,$this->config))->requireTables(['mail_accounts','mail_mcp_auth_contexts','mail_mcp_auth_states','mcp_mail_setup','mcp_operations','mail_local_messages','mail_recipients','mail_attachments']);
            $s=$this->db->createSchema();$prefix=$this->config->getSystemValueString('dbtableprefix','oc_');
            $a=$s->getTable($prefix.'mail_accounts')->getColumn('mcp_auth_revision');
            if(!$a->getType() instanceof BigIntType||!$a->getNotnull()||!in_array($a->getDefault(),[0,'0'],true))return false;
            $c=$s->getTable($prefix.'mcp_mail_setup')->getColumn('native_context_id');
            if(!$c->getType() instanceof StringType||$c->getLength()!==64||$c->getNotnull())return false;
            $contexts=$s->getTable($prefix.'mail_mcp_auth_contexts');$unique=false;
            foreach($contexts->getIndexes() as $index){$cols=$index->getColumns();sort($cols);if($index->isUnique()&&$cols===['source_key','user_id'])$unique=true;}
            if(!$unique)return false;
            $fields=[];
            foreach(['id'=>64,'source_key'=>64,'user_id'=>255,'kind'=>32,'phase'=>16] as $name=>$length)$fields[$name]=[StringType::class,$length,true];
            foreach(['target_id','activated_at','proof_revision','proof_at'] as $name)$fields[$name]=[BigIntType::class,null,false];
            foreach(['created_at','expires_at'] as $name)$fields[$name]=[BigIntType::class,null,true];
            $fields+=['intent_json'=>[TextType::class,null,true],'proof_kind'=>[StringType::class,24,false],'consumed_by'=>[StringType::class,128,false],'proof_protocols'=>[SmallIntType::class,null,true]];
            if(!$this->columns($contexts,$fields)||!in_array($contexts->getColumn('proof_protocols')->getDefault(),[0,'0'],true))return false;
            if($contexts->getPrimaryKey()?->getColumns()!==['id'])return false;
            $states=$s->getTable($prefix.'mail_mcp_auth_states');
            $fields=['id'=>[StringType::class,64,true],'context_id'=>[StringType::class,64,true],'attempt_id'=>[StringType::class,64,false]];
            foreach(['account_id','created_at','expires_at'] as $name)$fields[$name]=[BigIntType::class,null,true];
            foreach(['used','completed'] as $name)$fields[$name]=[BooleanType::class,null,true];
            if(!$this->columns($states,$fields))return false;
            foreach(['used','completed'] as $name){$column=$states->getColumn($name);if(!in_array($column->getDefault(),[false,0,'0'],true))return false;}
            return $states->getPrimaryKey()?->getColumns()===['id'];
        }catch(\Throwable){return false;}
    }
}
