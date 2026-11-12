<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Integration;
use OCA\Mcp\Mcp\Operations\BeforeEffectFailure;
use OCP\App\IAppManager;
use OCP\{IDBConnection,IConfig};
/** Native local alias mapper/entity qualification; no remote client or credential access. */
class MailAliasBoundary {
    private const FILES=[
        'lib/Db/Alias.php'=>'d15c35e0db73ae3938c4e491ab18ce5c47eae7f952ba7b11df40c7fb843816f7',
        'lib/Db/AliasMapper.php'=>'79cb77259b8b79cc1f602f5d27d10ca67c138f60cd5061a5d32b030e7280728c',
    ];
    public function __construct(private IAppManager $apps,private IDBConnection $db,private IConfig $config){}
    public function requireSupported():void {
        if(!MailRuntimePairs::matches($this->config->getSystemValueString('version',''),$this->apps->getAppVersion('mail')))throw new BeforeEffectFailure('This native Mail pair has no qualified alias write boundary.');
        if($this->apps->getAppVersion('mail')==='5.12.5.13'){
            // Current alias ownership overlays belong to the exact complete v13 source artifact.
            if(!(new MailNativeBoundary($this->apps))->deliverySourceVerified())throw new BeforeEffectFailure('The native alias mapper source is not qualified.');
        }else{
            $root=$this->apps->getAppPath('mail');foreach(self::FILES as $path=>$hash)if(!is_file($root.'/'.$path)||!hash_equals($hash,(string)hash_file('sha256',$root.'/'.$path)))throw new BeforeEffectFailure('The native alias mapper source is not qualified.');
        }
        if($this->config->getSystemValue('dbsharding',[])!==[]||$this->config->getSystemValue('dbreplica',[])!==[])throw new BeforeEffectFailure('Alias/journal atomicity requires primary storage without sharding or replicas.');
        $schema=$this->db->createSchema();$prefix=$this->config->getSystemValueString('dbtableprefix','oc_');
        foreach(['mail_aliases','mail_accounts','mail_local_messages','mcp_operations'] as $name){
            $table=$schema->getTable($prefix.$name);
            if($this->db->getDatabaseProvider()===IDBConnection::PLATFORM_MYSQL&&strtolower((string)($table->getOptions()['engine']??''))!=='innodb')throw new BeforeEffectFailure('Alias and journal storage must be transactional.');
        }
    }
}
