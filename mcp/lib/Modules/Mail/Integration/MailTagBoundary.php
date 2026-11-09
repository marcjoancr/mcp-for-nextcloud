<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IConfig;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
/** Pinned native local tag APIs; stock and guarded Mail share these unchanged source files. */
class MailTagBoundary {
    private const FILES=[
        'lib/Db/TagMapper.php'=>'78c82751d4e24659e9bcaa27034698a18efdcb81271c5730abc489fc0eaa629f',
        'lib/Db/Tag.php'=>'3dd25173b2c24e0afc79faa47ccbe5039bbf1822fccb649df5e32134ce2d4ab3',
        'lib/IMAP/ImapFlag.php'=>'3e1034befddb8489d5c462371892bf7489e46eb38e7b08cea3e430467207aa5a',
    ];
    public function __construct(private IAppManager $apps,private IDBConnection $db,private IConfig $config,private ?MailNativeBoundary $mutations=null){}
    public function verified():bool {
        try{$root=$this->apps->getAppPath('mail');foreach(self::FILES as$path=>$hash){if($path==='lib/Db/TagMapper.php'&&$this->mutations?->tagsSourceVerified())continue;if(!is_file($root.'/'.$path)||!hash_equals($hash,(string)hash_file('sha256',$root.'/'.$path)))return false;}return true;}catch(\Throwable){return false;}
    }
    public function requireStorage():void {
        if($this->config->getSystemValue('dbsharding',[])!==[])throw new BeforeEffectFailure('Local tag/journal atomicity is not qualified with database sharding.');
        $schema=$this->db->createSchema();$prefix=$this->config->getSystemValueString('dbtableprefix','oc_');$tags=$schema->getTable($prefix.'mail_tags');$ops=$schema->getTable($prefix.'mcp_operations');$unique=false;
        foreach($tags->getIndexes()as$index){$columns=$index->getColumns();sort($columns);if($index->isUnique()&&$columns===['imap_label','user_id'])$unique=true;}
        if(!$unique)throw new BeforeEffectFailure('The native unique owner/tag-label index is unavailable.');
        if($this->db->getDatabaseProvider()===IDBConnection::PLATFORM_MYSQL)foreach([$tags,$ops]as$table)if(strtolower((string)($table->getOptions()['engine']??''))!=='innodb')throw new BeforeEffectFailure('Local tag/journal tables do not have a qualified transactional engine.');
    }
}
